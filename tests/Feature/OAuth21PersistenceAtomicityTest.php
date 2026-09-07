<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthConsentStore;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('regrants revoked consent and rolls back partial client registration atomically', function (): void {
    DB::purge();
    $config = new ConfigRepository([
        'database' => [
            'default' => 'oauth',
            'connections' => ['oauth' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    $runner = new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)]);
    $clients = new DBLayerOAuthClientStore($factory, $tables);
    $consents = new DBLayerOAuthConsentStore($factory, $tables);
    $now = 1_700_000_100;
    $client = new OAuthClient(
        id: 'client-existing-id',
        clientId: 'oc_existing',
        type: OAuthClientType::Public,
        authenticationMethod: OAuthClientAuthenticationMethod::None,
        secretHash: null,
        grants: [OAuthGrantType::AuthorizationCode],
        audiences: ['https://api.example.test'],
        enabled: true,
        createdAt: $now - 100,
        updatedAt: $now - 100,
    );
    $scopes = ['profile.read'];
    $audiences = ['https://api.example.test'];
    $fingerprint = hash('sha256', implode("\0", $scopes) . "\0\0" . implode("\0", $audiences));

    try {
        $runner->run();
        $clients->register($client, ['https://client.example.test/callback'], $scopes);
        $consents->save(new OAuthConsent(
            id: 'consent-existing-id',
            accountId: 'account-1',
            clientId: $client->clientId,
            scopeFingerprint: $fingerprint,
            scopes: $scopes,
            audiences: $audiences,
            grantedAt: $now - 50,
            revokedAt: $now - 10,
            metadata: ['source' => 'existing'],
        ));

        $clock = new readonly class($now) implements ClockInterface {
            public function __construct(private int $now) {}
            public function now(): int { return $this->now; }
        };
        $authorizer = new class implements AuthorizerInterface {
            public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void
            {
                unset($principal, $ability, $resource, $context);
            }

            public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision
            {
                unset($principal, $ability, $resource, $context);

                return AuthorizationDecision::allow();
            }
        };
        $manager = new ConsentManager($consents, $authorizer, $clock);
        $request = new AuthorizationRequest(
            client: $client,
            redirectUri: 'https://client.example.test/callback',
            codeChallenge: str_repeat('A', 43),
            scopes: $scopes,
            audiences: $audiences,
        );

        $regranted = $manager->grant(new Principal('account-1', accountId: 'account-1'), $request);
        expect($regranted->id)->toBe('consent-existing-id')
            ->and($regranted->revokedAt)->toBeNull()
            ->and($regranted->grantedAt)->toBe($now)
            ->and($regranted->metadata)->toBe(['source' => 'existing'])
            ->and($consents->findActive('account-1', $client->clientId, $fingerprint)?->id)->toBe('consent-existing-id');

        $atomicClient = new OAuthClient(
            id: 'client-atomic-id',
            clientId: 'oc_atomic',
            type: OAuthClientType::Public,
            authenticationMethod: OAuthClientAuthenticationMethod::None,
            secretHash: null,
            grants: [OAuthGrantType::AuthorizationCode],
            audiences: ['https://api.example.test'],
            enabled: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $duplicateRedirect = 'https://atomic.example.test/callback';

        expect(fn() => $clients->register(
            $atomicClient,
            [$duplicateRedirect, $duplicateRedirect],
            ['profile.read'],
        ))->toThrow(RuntimeException::class);

        expect($clients->find('oc_atomic'))->toBeNull()
            ->and($clients->redirectUris('oc_atomic'))->toBe([])
            ->and($clients->scopes('oc_atomic'))->toBe([]);
    } finally {
        DB::purge();
    }
});
