<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationCodeStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthConsentStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAccessTokenService;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenCoordinator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

final class OAuth21FlowFixture
{
    public readonly AuthTables $tables;
    public readonly DBLayerFactory $factory;
    public readonly DBLayerOAuthClientStore $clientStore;
    public readonly DBLayerOAuthAuthorizationCodeStore $codeStore;
    public readonly DBLayerOAuthConsentStore $consentStore;
    public readonly DBLayerOAuthAuthorizationStore $authorizationStore;
    public readonly DBLayerOAuthRefreshTokenStore $refreshStore;
    public readonly OAuthClientManager $clients;
    public readonly OAuthScopeResolver $scopes;
    public readonly AuthorizationRequestValidator $requests;
    public readonly ConsentManager $consents;
    public readonly AuthorizationCodeManager $codes;
    public readonly OAuthRefreshTokenCoordinator $refreshTokens;
    public readonly EpicryptOAuthAccessTokenService $accessTokens;
    public readonly OAuthTokenManager $tokens;
    public readonly OAuthSigningKeySet $keys;
    public readonly ClockInterface $clock;
    public readonly AccountProviderInterface $accounts;

    public function __construct(public readonly int $now = 0)
    {
        DB::purge();
        $resolvedNow = $now > 0 ? $now : time();
        $this->clock = new readonly class($resolvedNow) implements ClockInterface {
            public function __construct(private int $now) {}
            public function now(): int { return $this->now; }
        };
        $this->accounts = new class implements AccountProviderInterface {
            public function findById(string $id): ?AccountInterface
            {
                return $id === 'account-1' ? new OAuth21FlowAccount($id) : null;
            }

            public function findByIdentifier(string $identifier): ?AccountInterface
            {
                return $identifier === 'account@example.test' ? new OAuth21FlowAccount('account-1') : null;
            }
        };
        $config = new ConfigRepository([
            'database' => [
                'default' => 'oauth-flow',
                'connections' => [
                    'oauth-flow' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
            'auth' => ['oauth' => ['scope_permissions' => []]],
        ]);
        $this->factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
        $this->tables = new AuthTables();
        new MigrationRunner($this->factory->connection(), [new AuthOAuthRevisionSchema($this->tables)])->run();

        $this->clientStore = new DBLayerOAuthClientStore($this->factory, $this->tables);
        $this->codeStore = new DBLayerOAuthAuthorizationCodeStore($this->factory, $this->tables);
        $this->consentStore = new DBLayerOAuthConsentStore($this->factory, $this->tables);
        $this->authorizationStore = new DBLayerOAuthAuthorizationStore($this->factory, $this->tables);
        $this->refreshStore = new DBLayerOAuthRefreshTokenStore($this->factory, $this->tables);

        $hasher = new class implements PasswordHasherInterface {
            public function hash(string $plainPassword, array $context = []): string
            {
                return hash('sha256', $plainPassword);
            }
        };
        $verifier = new class implements PasswordVerifierInterface {
            public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
            {
                return new PasswordVerificationResult(hash_equals(hash('sha256', $plainPassword), $storedHash));
            }
        };
        $authorizer = new class implements AuthorizerInterface {
            public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void {}
            public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision
            {
                return AuthorizationDecision::allow();
            }
        };
        $opaque = new OpaqueToken();
        $this->clients = new OAuthClientManager(
            $this->clientStore,
            $hasher,
            $verifier,
            $this->clock,
            $opaque,
            false,
        );
        $this->scopes = new OAuthScopeResolver($this->clientStore, $config);
        $this->requests = new AuthorizationRequestValidator($this->clients, $this->scopes);
        $this->consents = new ConsentManager($this->consentStore, $authorizer, $this->clock);
        $this->codes = new AuthorizationCodeManager(
            $this->codeStore,
            $this->authorizationStore,
            $authorizer,
            $this->clock,
            $opaque,
        );
        $this->refreshTokens = new OAuthRefreshTokenCoordinator(
            $this->refreshStore,
            $this->authorizationStore,
            $this->clients,
            $this->scopes,
            $this->accounts,
            $this->clock,
            $opaque,
        );

        $keyPair = KeyPairGenerator::ec()->generate();
        $algorithm = AsymmetricJwtAlgorithm::ES256;
        $issuer = 'https://issuer.example.test';
        $keyId = 'oauth-flow-key';
        $this->keys = new OAuthSigningKeySet(
            issuer: $issuer,
            activeKeyId: $keyId,
            privateKey: $keyPair['private'],
            publicKeys: new KeyRing([
                new KeyRingEntry(
                    id: $keyId,
                    key: $keyPair['public'],
                    status: KeyStatus::ACTIVE,
                    purpose: KeyPurpose::JWT_SIGNING,
                    algorithm: $algorithm->value,
                    issuer: $issuer,
                ),
            ]),
            algorithm: $algorithm,
        );
        $this->accessTokens = new EpicryptOAuthAccessTokenService($this->keys);
        $this->tokens = new OAuthTokenManager(
            $this->clients,
            $this->codes,
            $this->authorizationStore,
            $this->scopes,
            $this->accessTokens,
            $this->refreshTokens,
            $this->accounts,
            $this->clock,
            $this->keys,
        );
    }

    public function close(): void
    {
        DB::purge();
    }

    public static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}

final readonly class OAuth21FlowAccount implements AccountInterface
{
    public function __construct(private string $id) {}
    public function id(): string { return $this->id; }
    public function identifier(): string { return 'account@example.test'; }
    public function metadata(): array { return []; }
    public function passwordHash(): ?string { return null; }
    public function status(): AccountStatus { return AccountStatus::ACTIVE; }
}
