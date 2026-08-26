<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthConsentStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationStatus;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenCoordinator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

it('audits authorization denial revocation invalid redirect and invalid scope at their manager boundaries', function (): void {
    $now = 1_700_000_000;
    $client = oauth21AuditClient();
    $clients = oauth21AuditClientStore($client);
    $clientManager = new OAuthClientManager(
        $clients,
        oauth21AuditHasher(),
        oauth21AuditVerifier(),
        oauth21AuditClock($now),
        new OpaqueToken(),
        false,
    );
    $scopes = new OAuthScopeResolver($clients, new ConfigRepository(['auth' => ['oauth' => ['scope_permissions' => []]]]));
    $validator = new AuthorizationRequestValidator($clientManager, $scopes);
    $consentStore = new class implements OAuthConsentStoreInterface {
        public function find(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent { return null; }
        public function findActive(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent { return null; }
        public function revoke(string $accountId, string $clientId, int $revokedAt): int { return 1; }
        public function save(OAuthConsent $consent): void {}
    };
    $consents = new ConsentManager($consentStore, oauth21AuditAuthorizer(), oauth21AuditClock($now));
    $capture = new OAuthAuditCapture();
    $stub = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $manager = new OAuthManager(
        $validator,
        $consents,
        $stub(AuthorizationCodeManager::class),
        $stub(OAuthTokenManager::class),
        $stub(OAuthRevocationManager::class),
        $stub(OAuthIntrospectionManager::class),
        $stub(AuthorizationServerMetadata::class),
        new class implements JwkSetProviderInterface { public function jwks(): array { return ['keys' => []]; } },
        $clientManager,
        $capture->recorder($now),
    );
    $principal = new Principal('account-1', accountId: 'account-1');
    $request = new AuthorizationRequest(
        client: $client,
        redirectUri: 'https://client.example.test/callback',
        codeChallenge: str_repeat('A', 43),
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
    );

    $manager->deny($request, $principal);
    expect($manager->revokeConsent($principal, $client->clientId))->toBe(1);

    try {
        $manager->authorizationRedirectContext([
            'client_id' => $client->clientId,
            'redirect_uri' => 'https://attacker.example.test/callback',
        ]);
        throw new RuntimeException('Expected invalid redirect rejection.');
    } catch (OAuthProtocolException $exception) {
        expect($exception->error)->toBe('invalid_request');
    }

    try {
        $manager->validateAuthorizationRequest([
            'client_id' => $client->clientId,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'scope' => 'admin.write',
            'audience' => 'https://api.example.test',
        ]);
        throw new RuntimeException('Expected invalid scope rejection.');
    } catch (OAuthProtocolException $exception) {
        expect($exception->error)->toBe('invalid_scope');
    }

    expect(array_map(static fn($event): AuthEventType => $event->type, $capture->events))->toBe([
        AuthEventType::OAUTH_AUTHORIZATION_DENIED,
        AuthEventType::OAUTH_AUTHORIZATION_REVOKED,
        AuthEventType::OAUTH_INVALID_REQUEST,
        AuthEventType::OAUTH_INVALID_REQUEST,
    ])->and($capture->events[2]->metadata)->toMatchArray([
        'error' => 'invalid_request',
        'reason' => 'redirect_validation',
    ])->and($capture->events[3]->metadata)->toMatchArray([
        'error' => 'invalid_scope',
        'reason' => 'scope_validation',
    ]);
});

it('audits refresh rotation explicit revocation and reuse without recording the raw refresh token', function (): void {
    $now = 1_700_000_000;
    $plain = 'refresh-token-audit-sentinel';
    $tokens = new OpaqueToken();
    $authorization = new OAuthAuthorization(
        id: 'authorization-1',
        clientId: 'oc_client',
        accountId: 'account-1',
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
        createdAt: $now - 10,
        expiresAt: $now + 3600,
    );
    $base = new OAuthRefreshTokenRecord(
        id: 'refresh-1',
        tokenHash: $tokens->hash($plain),
        familyId: 'family-1',
        clientId: 'oc_client',
        accountId: 'account-1',
        deviceId: null,
        authorizationId: $authorization->id,
        scopes: $authorization->scopes,
        audiences: $authorization->audiences,
        issuedAt: $now - 10,
        expiresAt: $now + 3600,
    );
    $client = oauth21AuditClient();
    $clients = oauth21AuditClientStore($client);
    $authorizations = new class($authorization) implements OAuthAuthorizationStoreInterface {
        public function __construct(private OAuthAuthorization $authorization) {}
        public function find(string $authorizationId): ?OAuthAuthorization { return $this->authorization; }
        public function recent(int $limit = 100, ?string $clientId = null): array { return [$this->authorization]; }
        public function revoke(string $authorizationId, int $revokedAt): bool { return true; }
        public function save(OAuthAuthorization $authorization): void { $this->authorization = $authorization; }
    };
    $accounts = new class implements AccountProviderInterface {
        public function findById(string $id): ?AccountInterface { return oauth21AuditAccount($id); }
        public function findByIdentifier(string $identifier): ?AccountInterface { return null; }
    };
    $capture = new OAuthAuditCapture();

    $store = new class($base) implements OAuthRefreshTokenStoreInterface {
        public array $records;
        public function __construct(OAuthRefreshTokenRecord $record) { $this->records = [$record->tokenHash => $record]; }
        public function findByHash(string $tokenHash): ?OAuthRefreshTokenRecord { return $this->records[$tokenHash] ?? null; }
        public function revokeFamily(string $familyId, int $revokedAt): void {}
        public function rotate(string $tokenHash, OAuthRefreshTokenRecord $replacement, int $rotatedAt): OAuthRefreshRotationResult {
            $this->records[$replacement->tokenHash] = $replacement;
            return new OAuthRefreshRotationResult(OAuthRefreshRotationStatus::Rotated, $replacement);
        }
        public function save(OAuthRefreshTokenRecord $record): void { $this->records[$record->tokenHash] = $record; }
    };
    $coordinator = new OAuthRefreshTokenCoordinator(
        $store,
        $authorizations,
        new OAuthClientManager($clients, oauth21AuditHasher(), oauth21AuditVerifier(), oauth21AuditClock($now), $tokens, false),
        new OAuthScopeResolver($clients, new ConfigRepository(['auth' => ['oauth' => ['scope_permissions' => []]]])),
        $accounts,
        oauth21AuditClock($now),
        $tokens,
        audit: $capture->recorder($now),
    );

    $coordinator->rotate($plain, 'oc_client');
    $coordinator->revoke($plain, 'oc_client');

    $replayed = new OAuthRefreshTokenRecord(
        id: $base->id,
        tokenHash: $base->tokenHash,
        familyId: $base->familyId,
        clientId: $base->clientId,
        accountId: $base->accountId,
        deviceId: $base->deviceId,
        authorizationId: $base->authorizationId,
        scopes: $base->scopes,
        audiences: $base->audiences,
        issuedAt: $base->issuedAt,
        expiresAt: $base->expiresAt,
        rotatedAt: $now - 1,
    );
    $reuseStore = new class($replayed) implements OAuthRefreshTokenStoreInterface {
        public function __construct(private OAuthRefreshTokenRecord $record) {}
        public function findByHash(string $tokenHash): ?OAuthRefreshTokenRecord { return $this->record; }
        public function revokeFamily(string $familyId, int $revokedAt): void {}
        public function rotate(string $tokenHash, OAuthRefreshTokenRecord $replacement, int $rotatedAt): OAuthRefreshRotationResult { throw new LogicException('Not reached.'); }
        public function save(OAuthRefreshTokenRecord $record): void {}
    };
    $reuse = new OAuthRefreshTokenCoordinator(
        $reuseStore,
        $authorizations,
        new OAuthClientManager($clients, oauth21AuditHasher(), oauth21AuditVerifier(), oauth21AuditClock($now), $tokens, false),
        new OAuthScopeResolver($clients, new ConfigRepository(['auth' => ['oauth' => ['scope_permissions' => []]]])),
        $accounts,
        oauth21AuditClock($now),
        $tokens,
        audit: $capture->recorder($now),
    );

    expect(fn() => $reuse->rotate($plain, 'oc_client'))->toThrow(OAuthProtocolException::class);

    $types = array_map(static fn($event): AuthEventType => $event->type, $capture->events);
    expect($types)->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_ROTATED)
        ->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_REVOKED)
        ->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_REUSE);

    foreach ($capture->events as $event) {
        expect(json_encode($event->metadata, JSON_THROW_ON_ERROR))->not->toContain($plain);
    }
});

it('audits active signing-key selection failure without leaking key locators', function (): void {
    $privateLocator = '/deployment/private/oauth-active.pem';
    $publicLocator = '/deployment/public/oauth-fallback.pem';
    $config = AuthDefaults::all();
    $config['auth']['oauth']['issuer'] = 'https://issuer.example.test';
    $config['auth']['oauth']['signing']['active_key_id'] = 'active_key';
    $config['auth']['oauth']['signing']['private_key'] = $privateLocator;
    $config['auth']['oauth']['signing']['public_keys'] = [[
        'id' => 'fallback_key',
        'path' => $publicLocator,
        'status' => 'fallback',
    ]];
    $capture = new OAuthAuditCapture();
    $resolver = new OAuthSigningKeyResolver(new ConfigRepository($config), $capture->recorder());

    expect(fn() => $resolver->resolve())->toThrow(ConfigurationException::class);
    expect($capture->events)->toHaveCount(1)
        ->and($capture->events[0]->type)->toBe(AuthEventType::OAUTH_KEY_READINESS)
        ->and($capture->events[0]->metadata)->toBe(['result' => 'failure']);

    $encoded = json_encode($capture->events[0]->metadata, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($privateLocator)->not->toContain($publicLocator);
});

function oauth21AuditClient(): OAuthClient
{
    return new OAuthClient(
        id: 'client-id',
        clientId: 'oc_client',
        type: OAuthClientType::Public,
        authenticationMethod: OAuthClientAuthenticationMethod::None,
        secretHash: null,
        grants: [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
        audiences: ['https://api.example.test'],
        enabled: true,
        createdAt: 1,
        updatedAt: 1,
    );
}

function oauth21AuditClientStore(OAuthClient $client): OAuthClientStoreInterface
{
    return new class($client) implements OAuthClientStoreInterface {
        public function __construct(private OAuthClient $client) {}
        public function find(string $clientId): ?OAuthClient { return $clientId === $this->client->clientId ? $this->client : null; }
        public function list(int $limit = 100): array { return [$this->client]; }
        public function redirectUris(string $clientId): array { return ['https://client.example.test/callback']; }
        public function scopes(string $clientId): array { return ['profile.read']; }
        public function register(OAuthClient $client, array $redirectUris, array $scopes): void { $this->client = $client; }
        public function save(OAuthClient $client): void { $this->client = $client; }
        public function replaceRedirectUris(string $clientId, array $redirectUris, int $createdAt): void {}
        public function replaceScopes(string $clientId, array $scopes, int $createdAt): void {}
    };
}

function oauth21AuditClock(int $now): ClockInterface
{
    return new readonly class($now) implements ClockInterface {
        public function __construct(private int $now) {}
        public function now(): int { return $this->now; }
    };
}

function oauth21AuditHasher(): PasswordHasherInterface
{
    return new class implements PasswordHasherInterface {
        public function hash(string $plainPassword, array $context = []): string { return 'hash:' . $plainPassword; }
    };
}

function oauth21AuditVerifier(): PasswordVerifierInterface
{
    return new class implements PasswordVerifierInterface {
        public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult { throw new LogicException('Not used.'); }
    };
}

function oauth21AuditAuthorizer(): AuthorizerInterface
{
    return new class implements AuthorizerInterface {
        public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void {}
        public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision { return AuthorizationDecision::allow(); }
    };
}

function oauth21AuditAccount(string $id): AccountInterface
{
    return new readonly class($id) implements AccountInterface {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
        public function identifier(): string { return $this->id . '@example.test'; }
        public function metadata(): array { return []; }
        public function passwordHash(): ?string { return null; }
        public function status(): AccountStatus { return AccountStatus::ACTIVE; }
    };
}
