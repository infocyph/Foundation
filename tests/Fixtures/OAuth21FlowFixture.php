<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeArtifact;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenService;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenStatusStoreInterface;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeConsumer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeIssuer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAssertionValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthDpopValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthIntrospectionEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthResourceAccessTokenValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthRevocationEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenArtifact;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenManager;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdIdTokenIssuer;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdTokenResponseExtension;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\DpopProof;
use Infocyph\Epicrypt\Token\Jwt\Enum\JweKeyManagementAlgorithm;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptAccessTokenStatusStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptAuthorizationCodeStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptJwtReplayStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthConsentStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationClientStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthScopeAudienceResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdSubjectProvider;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptProtocolSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

final class OAuth21FlowFixture
{
    public readonly AccountProviderInterface $accounts;
    public readonly OAuth21AccessTokenHarness $accessTokens;
    public readonly DBLayerOAuthAuthorizationStore $authorizationStore;
    public readonly OAuthAccessTokenValidator $accessValidator;
    public readonly OAuthClientManager $clients;
    public readonly OAuth21FlowClock $clock;
    public readonly AuthorizationCodeManager $codes;
    public readonly ConsentManager $consents;
    public readonly DBLayerFactory $factory;
    public readonly OAuthIntrospectionManager $introspection;
    public readonly ?AsymmetricSigningKeySet $openIdKeys;
    public readonly OAuthSigningKeySet $keys;
    public readonly RefreshTokenManager $refreshTokens;
    public readonly DBLayerOAuthRefreshTokenStore $refreshStore;
    public readonly AuthorizationRequestValidator $requests;
    public readonly OAuthRevocationManager $revocation;
    public readonly OAuthScopeResolver $scopes;
    public readonly AuthTables $tables;
    public readonly OAuthTokenManager $tokens;

    public function __construct(
        public readonly int $now = 0,
        ?OAuthAuditRecorder $audit = null,
        bool $openId = false,
    )
    {
        DB::purge();
        $resolvedNow = $now > 0 ? $now : time();
        $this->clock = new OAuth21FlowClock($resolvedNow);
        $psrClock = new EpicryptClockAdapter($this->clock);

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

        $issuer = 'https://issuer.example.test';
        $config = new ConfigRepository([
            'database' => [
                'default' => 'oauth-flow',
                'connections' => [
                    'oauth-flow' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
            'auth' => [
                'oauth' => [
                    'issuer' => $issuer,
                    'oidc' => ['enabled' => $openId],
                    'routes' => ['token' => '/oauth/token'],
                    'scope_permissions' => [],
                    'scope_audiences' => [],
                ],
            ],
        ]);
        $this->factory = new DBLayerFactory(
            new DatabaseConnectionResolver($config),
            self::container(),
        );
        $this->tables = new AuthTables();
        new MigrationRunner($this->factory->connection(), [
            new AuthOAuthRevisionSchema($this->tables),
            new AuthOAuthEpicryptRevisionSchema($this->tables),
            new AuthOAuthEpicryptProtocolSchema($this->tables),
        ])->run();

        $clientStore = new DBLayerOAuthClientStore($this->factory, $this->tables);
        $consentStore = new DBLayerOAuthConsentStore($this->factory, $this->tables);
        $this->authorizationStore = new DBLayerOAuthAuthorizationStore($this->factory, $this->tables);
        $this->refreshStore = new DBLayerOAuthRefreshTokenStore($this->factory, $this->tables);

        $this->clients = new OAuthClientManager(
            $clientStore,
            self::hasher(),
            self::verifier(),
            $this->clock,
            new OpaqueToken(),
            false,
        );
        $this->scopes = new OAuthScopeResolver($clientStore, $config);
        $this->requests = new AuthorizationRequestValidator($this->clients, $this->scopes, $config);
        $authorizer = self::authorizer();
        $this->consents = new ConsentManager($consentStore, $authorizer, $this->clock);

        $epicryptAuthorizations = new DBLayerEpicryptOAuthAuthorizationStore($this->factory, $this->tables);
        $epicryptCodes = new DBLayerEpicryptAuthorizationCodeStore($this->factory, $this->tables);
        $epicryptRefresh = new DBLayerEpicryptRefreshTokenStore($this->factory, $this->tables);
        $status = new DBLayerEpicryptAccessTokenStatusStore($this->factory, $this->tables);
        $replay = new DBLayerEpicryptJwtReplayStore($this->factory, $this->tables);

        $codeArtifact = new AuthorizationCodeArtifact(
            self::protectionKeys(KeyPurpose::OAUTH_AUTHORIZATION_CODE_PROTECTION, $issuer, 'code'),
            $issuer,
            $psrClock,
        );
        $codeIssuer = new OAuthAuthorizationCodeIssuer(
            $epicryptAuthorizations,
            $epicryptCodes,
            $codeArtifact,
            $psrClock,
        );
        $codeConsumer = new OAuthAuthorizationCodeConsumer(
            $codeArtifact,
            $epicryptCodes,
            $epicryptAuthorizations,
            $psrClock,
        );
        $this->codes = new AuthorizationCodeManager(
            $codeIssuer,
            $codeConsumer,
            $authorizer,
            $this->clock,
            audit: $audit,
        );

        $keyPair = KeyPairGenerator::ec()->generate();
        $algorithm = AsymmetricJwtAlgorithm::ES256;
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
                    purpose: KeyPurpose::OAUTH_ACCESS_TOKEN_SIGNING,
                    algorithm: $algorithm->value,
                    issuer: $issuer,
                ),
            ]),
            algorithm: $algorithm,
        );

        $nativeAccess = new OAuthAccessTokenService(
            $this->keys->epicrypt,
            $epicryptAuthorizations,
            $status,
            300,
            $psrClock,
        );
        $refreshArtifact = new RefreshTokenArtifact(
            self::protectionKeys(KeyPurpose::OAUTH_REFRESH_TOKEN_PROTECTION, $issuer, 'refresh'),
            $issuer,
            $psrClock,
        );
        $this->refreshTokens = new RefreshTokenManager($epicryptRefresh, $refreshArtifact, $psrClock);

        $clientProjection = new EpicryptOAuthAuthorizationClientStore($this->clients);
        $dpop = new OAuthDpopValidator(
            $replay,
            AsymmetricJwtAlgorithm::ES256,
            new DpopProof($psrClock),
        );
        $authenticator = new OAuthClientAuthenticator(
            $clientProjection,
            new OAuthClientAssertionValidator($replay, $psrClock),
        );
        $authentication = new EpicryptOAuthClientAuthenticationAdapter($authenticator, $config);
        $audiences = new EpicryptOAuthScopeAudienceResolver($this->clients, $this->scopes, $config);

        $openIdExtension = null;
        $this->openIdKeys = null;
        if ($openId) {
            $openIdPair = KeyPairGenerator::ec()->generate();
            $openIdAlgorithm = AsymmetricJwtAlgorithm::ES256;
            $openIdKeyId = 'oidc-flow-key';
            $this->openIdKeys = new AsymmetricSigningKeySet(
                issuer: $issuer,
                activeKeyId: $openIdKeyId,
                privateKey: $openIdPair['private'],
                publicKeys: new KeyRing([
                    new KeyRingEntry(
                        id: $openIdKeyId,
                        key: $openIdPair['public'],
                        status: KeyStatus::ACTIVE,
                        purpose: KeyPurpose::OIDC_ID_TOKEN_SIGNING,
                        algorithm: $openIdAlgorithm->value,
                        issuer: $issuer,
                    ),
                ]),
                algorithm: $openIdAlgorithm,
                purpose: KeyPurpose::OIDC_ID_TOKEN_SIGNING,
            );
            $openIdExtension = new OpenIdTokenResponseExtension(new OpenIdIdTokenIssuer(
                $this->openIdKeys,
                new FoundationOpenIdSubjectProvider(),
                300,
                $psrClock,
            ));
        }

        $endpoint = new OAuthTokenEndpoint(
            $clientProjection,
            $nativeAccess,
            $codeConsumer,
            $this->refreshTokens,
            $epicryptAuthorizations,
            $audiences,
            $dpop,
            'https://issuer.example.test/oauth/token',
            $psrClock,
            $openIdExtension,
        );
        $this->tokens = new OAuthTokenManager($endpoint, $authentication, $this->refreshTokens, $audit);

        $resourceValidator = new OAuthResourceAccessTokenValidator($nativeAccess, $dpop);
        $this->accessValidator = new OAuthAccessTokenValidator(
            $resourceValidator,
            $this->clients,
            $epicryptAuthorizations,
            $this->accounts,
        );
        $this->revocation = new OAuthRevocationManager(
            new OAuthRevocationEndpoint(
                $clientProjection,
                $nativeAccess,
                $this->refreshTokens,
                $epicryptAuthorizations,
                $psrClock,
            ),
            $authentication,
            $nativeAccess,
            $this->refreshTokens,
            $audit,
        );
        $this->introspection = new OAuthIntrospectionManager(
            new OAuthIntrospectionEndpoint($nativeAccess, $this->refreshTokens),
            $authentication,
        );
        $this->accessTokens = new OAuth21AccessTokenHarness($nativeAccess);
    }

    public function advance(int $seconds): void
    {
        $this->clock->advance($seconds);
    }

    public function close(): void
    {
        DB::purge();
    }

    public static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function authorizer(): AuthorizerInterface
    {
        return new class implements AuthorizerInterface {
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
    }

    private static function container(): ContainerInterface
    {
        $state = new RuntimeExecutionState();

        return new readonly class($state) implements ContainerInterface {
            public function __construct(private RuntimeExecutionState $state) {}

            public function get(string $id): mixed
            {
                if ($id === RuntimeExecutionState::class) {
                    return $this->state;
                }

                throw new \LogicException(sprintf('Fixture container has no service "%s".', $id));
            }

            public function has(string $id): bool
            {
                return $id === RuntimeExecutionState::class;
            }
        };
    }

    private static function hasher(): PasswordHasherInterface
    {
        return new class implements PasswordHasherInterface {
            public function hash(string $plainPassword, array $context = []): string
            {
                unset($context);

                return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);
            }
        };
    }

    private static function protectionKeys(KeyPurpose $purpose, string $issuer, string $id): KeyRing
    {
        return new KeyRing([
            new KeyRingEntry(
                id: $id,
                key: random_bytes(32),
                status: KeyStatus::ACTIVE,
                purpose: $purpose,
                algorithm: JweKeyManagementAlgorithm::DIRECT->value,
                issuer: $issuer,
            ),
        ]);
    }

    private static function verifier(): PasswordVerifierInterface
    {
        return new class implements PasswordVerifierInterface {
            public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
            {
                return new PasswordVerificationResult(password_verify($plainPassword, $storedHash));
            }
        };
    }
}
