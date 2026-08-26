<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenRevocation;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenCoordinator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

/**
 * @return array{
 *     manager: OAuthRevocationManager,
 *     persisted: ArrayObject<int, OAuthAccessTokenRevocation>,
 *     capture: OAuthAuditCapture
 * }
 */
function oauthRevocationAuditFixture(string $claimClientId = 'oc_client'): array
{
    $now = 1_700_000_000;
    $client = new OAuthClient(
        id: 'client-record',
        clientId: 'oc_client',
        type: OAuthClientType::Public,
        authenticationMethod: OAuthClientAuthenticationMethod::None,
        secretHash: null,
        grants: [OAuthGrantType::AuthorizationCode],
        audiences: ['https://api.example.test'],
        enabled: true,
        createdAt: $now - 100,
        updatedAt: $now - 100,
    );
    $clients = new class($client) implements OAuthClientStoreInterface {
        public function __construct(private OAuthClient $client) {}
        public function find(string $clientId): ?OAuthClient { return $clientId === $this->client->clientId ? $this->client : null; }
        public function list(int $limit = 100): array { return [$this->client]; }
        public function redirectUris(string $clientId): array { return []; }
        public function scopes(string $clientId): array { return ['profile:read']; }
        public function register(OAuthClient $client, array $redirectUris, array $scopes): void {}
        public function save(OAuthClient $client): void {}
        public function replaceRedirectUris(string $clientId, array $redirectUris, int $createdAt): void {}
        public function replaceScopes(string $clientId, array $scopes, int $createdAt): void {}
    };
    $clock = new readonly class($now) implements ClockInterface {
        public function __construct(private int $time) {}
        public function now(): int { return $this->time; }
    };
    $clientManager = new OAuthClientManager(
        clients: $clients,
        hasher: new class implements PasswordHasherInterface {
            public function hash(string $plainPassword, array $context = []): string { throw new LogicException('Not used for public client authentication.'); }
        },
        verifier: new class implements PasswordVerifierInterface {
            public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult { throw new LogicException('Not used for public client authentication.'); }
        },
        clock: $clock,
        tokens: new OpaqueToken(),
        production: true,
    );
    $authorizations = new class implements OAuthAuthorizationStoreInterface {
        public function find(string $authorizationId): ?OAuthAuthorization { return null; }
        public function recent(int $limit = 100, ?string $clientId = null): array { return []; }
        public function revoke(string $authorizationId, int $revokedAt): bool { return false; }
        public function save(OAuthAuthorization $authorization): void {}
    };
    $refreshStore = new class implements OAuthRefreshTokenStoreInterface {
        public function findByHash(string $tokenHash): ?OAuthRefreshTokenRecord { return null; }
        public function revokeFamily(string $familyId, int $revokedAt): void {}
        public function rotate(string $tokenHash, OAuthRefreshTokenRecord $replacement, int $rotatedAt): OAuthRefreshRotationResult { throw new LogicException('Not used by access-token revocation.'); }
        public function save(OAuthRefreshTokenRecord $record): void {}
    };
    $refreshTokens = new OAuthRefreshTokenCoordinator(
        refreshTokens: $refreshStore,
        authorizations: $authorizations,
        clients: $clientManager,
        scopes: new OAuthScopeResolver($clients, new ConfigRepository()),
        accounts: new class implements AccountProviderInterface {
            public function findById(string $id): ?AccountInterface { return null; }
            public function findByIdentifier(string $identifier): ?AccountInterface { return null; }
        },
        clock: $clock,
        tokens: new OpaqueToken(),
    );
    $accessTokens = new class($claimClientId, $now) implements OAuthAccessTokenServiceInterface {
        public function __construct(private string $clientId, private int $now) {}
        public function issue(OAuthAccessTokenClaims $claims): string { throw new LogicException('Not used by revocation.'); }
        public function verify(string $token, string $expectedAudience): OAuthAccessTokenClaims
        {
            return new OAuthAccessTokenClaims(
                issuer: 'https://issuer.example.test',
                subject: 'account-1',
                audiences: [$expectedAudience],
                expiresAt: $this->now + 300,
                issuedAt: $this->now - 10,
                tokenId: 'access-token-id',
                clientId: $this->clientId,
                scopes: ['profile:read'],
                authorizationId: 'authorization-1',
            );
        }
    };
    /** @var ArrayObject<int, OAuthAccessTokenRevocation> $persisted */
    $persisted = new ArrayObject();
    $revocations = new class($persisted) implements OAuthAccessRevocationStoreInterface {
        /** @param ArrayObject<int, OAuthAccessTokenRevocation> $records */
        public function __construct(private ArrayObject $records) {}
        public function isRevoked(string $tokenId, int $now): bool { return false; }
        public function revoke(OAuthAccessTokenRevocation $revocation): void { $this->records->append($revocation); }
    };
    $capture = new OAuthAuditCapture();

    return [
        'manager' => new OAuthRevocationManager(
            clients: $clientManager,
            accessTokens: $accessTokens,
            revocations: $revocations,
            refreshTokens: $refreshTokens,
            clock: $clock,
            audit: $capture->recorder($now),
        ),
        'persisted' => $persisted,
        'capture' => $capture,
    ];
}

it('audits access-token revocation only after the durable revocation write', function (): void {
    $fixture = oauthRevocationAuditFixture();
    $token = 'raw-access-token-sentinel';

    $fixture['manager']->revoke(
        $token,
        new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, 'oc_client'),
        'access_token',
    );

    expect($fixture['persisted'])->toHaveCount(1)
        ->and($fixture['persisted'][0]->tokenId)->toBe('access-token-id')
        ->and($fixture['capture']->events)->toHaveCount(1)
        ->and($fixture['capture']->events[0]->type)->toBe(AuthEventType::OAUTH_ACCESS_TOKEN_REVOKED)
        ->and($fixture['capture']->events[0]->metadata)->toBe([
            'authorization_id' => 'authorization-1',
            'client_id' => 'oc_client',
            'result' => 'revoked',
            'token_type' => 'access_token',
        ])
        ->and(json_encode($fixture['capture']->events[0]->metadata, JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('does not audit or persist an access-token revocation for a token owned by another client', function (): void {
    $fixture = oauthRevocationAuditFixture('oc_other');

    $fixture['manager']->revoke(
        'other-client-token',
        new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, 'oc_client'),
        'access_token',
    );

    expect($fixture['persisted'])->toHaveCount(0)
        ->and($fixture['capture']->events)->toBe([]);
});
