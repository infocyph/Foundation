<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeArtifact;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeStoreInterface as EpicryptAuthorizationCodeStore;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenService as EpicryptAccessTokenService;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenStatusStoreInterface as EpicryptAccessTokenStatusStore;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeConsumer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeIssuer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationStoreInterface as EpicryptAuthorizationStore;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAssertionValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientStoreInterface as EpicryptClientStore;
use Infocyph\Epicrypt\Auth\OAuth\OAuthDpopValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthIntrospectionEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthJwksPublisher;
use Infocyph\Epicrypt\Auth\OAuth\OAuthResourceAccessTokenValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthRevocationEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenArtifact;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenManager;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenStoreInterface as EpicryptRefreshTokenStore;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdClaimsProviderInterface;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdIdTokenIssuer;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionPolicy;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdSubjectIdentifierProviderInterface;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdTokenResponseExtension;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdUserInfoProjector;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\JwtReplayStoreInterface;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptAccessTokenStatusStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptAuthorizationCodeStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptJwtReplayStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthConsentStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationClientStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthJwkSetProvider;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthScopeAudienceResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\OAuthProtectionKeyResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdClaimsProvider;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdSubjectProvider;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthConsentStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\Metadata\OpenIdMetadataProvider;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\SessionConfig;
use Psr\Clock\ClockInterface as PsrClock;

final readonly class AuthOAuthRegistrar extends AbstractAuthRegistrar
{
    private const string AUTHORIZATION_CODE_KEYS = 'foundation.oauth.epicrypt.authorization-code-keys';

    private const string OPENID_SIGNING_KEYS = 'foundation.oauth.epicrypt.openid-signing-keys';

    private const string REFRESH_TOKEN_KEYS = 'foundation.oauth.epicrypt.refresh-token-keys';

    public function enabled(): bool
    {
        return $this->boolConfig('auth.oauth.enabled', false);
    }

    public function register(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->requirePackage(Connection::class, 'infocyph/dblayer', 'database');
        $this->requirePackage(AsymmetricJwt::class, 'infocyph/epicrypt', 'crypto');
        $this->requirePackage(\Infocyph\CacheLayer\Cache\Cache::class, 'infocyph/cachelayer', 'cache');

        $this->registerFoundationStores();
        $this->registerFoundationPolicy();
        $this->registerEpicryptStores();
        $this->registerEpicryptProtocol();
        $this->registerFoundationFacades();
    }

    private function authConnection(): ?string
    {
        $connection = $this->app->config()->get('database.default');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function registerEpicryptProtocol(): void
    {
        $issuer = $this->stringConfig('auth.oauth.issuer', '');
        $openId = $this->boolConfig('auth.oauth.oidc.enabled', false);

        $this->recipe(PsrClock::class, EpicryptClockAdapter::class, [
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(OAuthProtectionKeyResolver::class, OAuthProtectionKeyResolver::class, [
            $this->ref(ConfigRepository::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->staticRecipe(
            self::AUTHORIZATION_CODE_KEYS,
            AuthOAuthGraphFactory::class,
            'authorizationCodeKeys',
            [$this->ref(OAuthProtectionKeyResolver::class)],
        );
        $this->staticRecipe(
            self::REFRESH_TOKEN_KEYS,
            AuthOAuthGraphFactory::class,
            'refreshTokenKeys',
            [$this->ref(OAuthProtectionKeyResolver::class)],
        );
        $this->staticRecipe(
            AsymmetricSigningKeySet::class,
            AuthOAuthGraphFactory::class,
            'epicryptSigningKeySet',
            [$this->ref(OAuthSigningKeySet::class)],
        );

        if ($openId) {
            $this->staticRecipe(
                self::OPENID_SIGNING_KEYS,
                AuthOAuthGraphFactory::class,
                'openIdSigningKeySet',
                [$this->ref(ConfigRepository::class)],
            );
            $this->recipe(
                OpenIdSubjectIdentifierProviderInterface::class,
                FoundationOpenIdSubjectProvider::class,
            );
            $this->recipe(OpenIdClaimsProviderInterface::class, FoundationOpenIdClaimsProvider::class, [
                $this->ref(AccountProviderInterface::class),
            ]);
            $this->recipe(OpenIdIdTokenIssuer::class, OpenIdIdTokenIssuer::class, [
                $this->ref(self::OPENID_SIGNING_KEYS),
                $this->ref(OpenIdSubjectIdentifierProviderInterface::class),
                $this->intConfig('auth.oauth.oidc.id_token_lifetime_seconds', 300),
                $this->ref(PsrClock::class),
            ]);
            $this->recipe(OpenIdTokenResponseExtension::class, OpenIdTokenResponseExtension::class, [
                $this->ref(OpenIdIdTokenIssuer::class),
            ]);
            $this->recipe(OpenIdUserInfoProjector::class, OpenIdUserInfoProjector::class, [
                $this->ref(OpenIdSubjectIdentifierProviderInterface::class),
                $this->ref(OpenIdClaimsProviderInterface::class),
            ]);
            $this->recipe(OpenIdInteractionPolicy::class, OpenIdInteractionPolicy::class, [
                $this->ref(PsrClock::class),
            ]);
        }

        $this->recipe(AuthorizationCodeArtifact::class, AuthorizationCodeArtifact::class, [
            $this->ref(self::AUTHORIZATION_CODE_KEYS),
            $issuer,
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(OAuthAuthorizationCodeIssuer::class, OAuthAuthorizationCodeIssuer::class, [
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(EpicryptAuthorizationCodeStore::class),
            $this->ref(AuthorizationCodeArtifact::class),
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(OAuthAuthorizationCodeConsumer::class, OAuthAuthorizationCodeConsumer::class, [
            $this->ref(AuthorizationCodeArtifact::class),
            $this->ref(EpicryptAuthorizationCodeStore::class),
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(PsrClock::class),
        ]);

        $this->recipe(RefreshTokenArtifact::class, RefreshTokenArtifact::class, [
            $this->ref(self::REFRESH_TOKEN_KEYS),
            $issuer,
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(RefreshTokenManager::class, RefreshTokenManager::class, [
            $this->ref(EpicryptRefreshTokenStore::class),
            $this->ref(RefreshTokenArtifact::class),
            $this->ref(PsrClock::class),
        ]);

        $this->recipe(EpicryptAccessTokenService::class, EpicryptAccessTokenService::class, [
            $this->ref(AsymmetricSigningKeySet::class),
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(EpicryptAccessTokenStatusStore::class),
            $this->intConfig('auth.oauth.access_token_ttl', 300),
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(EpicryptOAuthScopeAudienceResolver::class, EpicryptOAuthScopeAudienceResolver::class, [
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(ConfigRepository::class),
        ]);

        $this->recipe(OAuthClientAssertionValidator::class, OAuthClientAssertionValidator::class, [
            $this->ref(JwtReplayStoreInterface::class),
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(OAuthClientAuthenticator::class, OAuthClientAuthenticator::class, [
            $this->ref(EpicryptClientStore::class),
            $this->ref(OAuthClientAssertionValidator::class),
        ]);
        $this->recipe(EpicryptOAuthClientAuthenticationAdapter::class, EpicryptOAuthClientAuthenticationAdapter::class, [
            $this->ref(OAuthClientAuthenticator::class),
            $this->ref(ConfigRepository::class),
        ]);
        $this->recipe(OAuthDpopValidator::class, OAuthDpopValidator::class, [
            $this->ref(JwtReplayStoreInterface::class),
        ]);

        $this->staticRecipe(
            'foundation.oauth.token-endpoint-uri',
            AuthOAuthGraphFactory::class,
            'endpointUri',
            [$this->ref(ConfigRepository::class), 'token'],
        );
        $this->recipe(OAuthTokenEndpoint::class, OAuthTokenEndpoint::class, [
            $this->ref(EpicryptClientStore::class),
            $this->ref(EpicryptAccessTokenService::class),
            $this->ref(OAuthAuthorizationCodeConsumer::class),
            $this->ref(RefreshTokenManager::class),
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(EpicryptOAuthScopeAudienceResolver::class),
            $this->ref(OAuthDpopValidator::class),
            $this->ref('foundation.oauth.token-endpoint-uri'),
            $this->ref(PsrClock::class),
            $openId ? $this->ref(OpenIdTokenResponseExtension::class) : null,
        ]);
        $this->recipe(OAuthRevocationEndpoint::class, OAuthRevocationEndpoint::class, [
            $this->ref(EpicryptClientStore::class),
            $this->ref(EpicryptAccessTokenService::class),
            $this->ref(RefreshTokenManager::class),
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(PsrClock::class),
        ]);
        $this->recipe(OAuthIntrospectionEndpoint::class, OAuthIntrospectionEndpoint::class, [
            $this->ref(EpicryptAccessTokenService::class),
            $this->ref(RefreshTokenManager::class),
        ]);
        $this->recipe(OAuthResourceAccessTokenValidator::class, OAuthResourceAccessTokenValidator::class, [
            $this->ref(EpicryptAccessTokenService::class),
            $this->ref(OAuthDpopValidator::class),
        ]);
        $this->recipe(OAuthJwksPublisher::class, OAuthJwksPublisher::class, [
            $this->ref(AsymmetricSigningKeySet::class),
        ]);
    }

    private function registerEpicryptStores(): void
    {
        $connection = $this->authConnection();
        $stores = [
            EpicryptAuthorizationCodeStore::class => DBLayerEpicryptAuthorizationCodeStore::class,
            EpicryptAuthorizationStore::class => DBLayerEpicryptOAuthAuthorizationStore::class,
            EpicryptRefreshTokenStore::class => DBLayerEpicryptRefreshTokenStore::class,
            EpicryptAccessTokenStatusStore::class => DBLayerEpicryptAccessTokenStatusStore::class,
            JwtReplayStoreInterface::class => DBLayerEpicryptJwtReplayStore::class,
        ];
        foreach ($stores as $id => $implementation) {
            $this->recipe($id, $implementation, [
                $this->ref(DBLayerFactory::class),
                $this->ref(AuthTables::class),
                $connection,
            ]);
        }
        $this->recipe(EpicryptClientStore::class, EpicryptOAuthAuthorizationClientStore::class, [
            $this->ref(OAuthClientManager::class),
        ]);
    }

    private function registerFoundationFacades(): void
    {
        $openId = $this->boolConfig('auth.oauth.oidc.enabled', false);
        $this->recipe(AuthorizationCodeManager::class, AuthorizationCodeManager::class, [
            $this->ref(OAuthAuthorizationCodeIssuer::class),
            $this->ref(OAuthAuthorizationCodeConsumer::class),
            $this->ref(AuthorizerInterface::class),
            $this->ref(ClockInterface::class),
            $this->intConfig('auth.oauth.authorization_code_ttl', 60),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthAccessTokenValidator::class, OAuthAccessTokenValidator::class, [
            $this->ref(OAuthResourceAccessTokenValidator::class),
            $this->ref(OAuthClientManager::class),
            $this->ref(EpicryptAuthorizationStore::class),
            $this->ref(AccountProviderInterface::class),
        ]);
        $this->recipe(OAuthTokenManager::class, OAuthTokenManager::class, [
            $this->ref(OAuthTokenEndpoint::class),
            $this->ref(EpicryptOAuthClientAuthenticationAdapter::class),
            $this->ref(RefreshTokenManager::class),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthRevocationManager::class, OAuthRevocationManager::class, [
            $this->ref(OAuthRevocationEndpoint::class),
            $this->ref(EpicryptOAuthClientAuthenticationAdapter::class),
            $this->ref(EpicryptAccessTokenService::class),
            $this->ref(RefreshTokenManager::class),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthIntrospectionManager::class, OAuthIntrospectionManager::class, [
            $this->ref(OAuthIntrospectionEndpoint::class),
            $this->ref(EpicryptOAuthClientAuthenticationAdapter::class),
        ]);
        $this->recipe(AuthorizationServerMetadata::class, AuthorizationServerMetadata::class, [
            $this->ref(ConfigRepository::class),
        ]);
        if ($openId) {
            $this->recipe(OpenIdMetadataProvider::class, OpenIdMetadataProvider::class, [
                $this->ref(AuthorizationServerMetadata::class),
                $this->ref(self::OPENID_SIGNING_KEYS),
                $this->ref(ConfigRepository::class),
            ]);
        }
        $this->recipe(JwkSetProviderInterface::class, EpicryptOAuthJwkSetProvider::class, [
            $this->ref(OAuthSigningKeySet::class),
            $openId ? $this->ref(self::OPENID_SIGNING_KEYS) : null,
        ]);

        $this->recipe(OAuthManager::class, OAuthManager::class, [
            $this->ref(AuthorizationRequestValidator::class),
            $this->ref(ConsentManager::class),
            $this->ref(AuthorizationCodeManager::class),
            $this->ref(OAuthTokenManager::class),
            $this->ref(OAuthRevocationManager::class),
            $this->ref(OAuthIntrospectionManager::class),
            $this->ref(AuthorizationServerMetadata::class),
            $this->ref(JwkSetProviderInterface::class),
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthAuditRecorder::class),
            $openId ? $this->ref(OpenIdMetadataProvider::class) : null,
            $openId ? $this->ref(OpenIdUserInfoProjector::class) : null,
            $openId ? $this->ref(OAuthAccessTokenValidator::class) : null,
        ]);
        $this->recipe(OAuthHttpInput::class, OAuthHttpInput::class);
        $this->recipe(OAuthHttpResponseFactory::class, OAuthHttpResponseFactory::class);
        $this->recipe(OAuthHttpThrottleFactory::class, OAuthHttpThrottleFactory::class, [
            $this->ref(ConfigRepository::class),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthHttpHandler::class, OAuthHttpHandler::class, [
            $this->ref(OAuthManager::class),
            $this->ref(OAuthHttpInput::class),
            $this->ref(OAuthHttpResponseFactory::class),
            $this->ref(ConfigRepository::class),
        ]);
        $this->recipe(OAuthAuthorizationController::class, OAuthAuthorizationController::class, [
            $this->ref(OAuthHttpHandler::class),
            $this->ref(CurrentPrincipalContext::class),
            $this->ref(SessionConfig::class),
        ]);
    }

    private function registerFoundationPolicy(): void
    {
        $this->recipe(OAuthAuditRecorder::class, OAuthAuditRecorder::class, [
            $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(OpaqueToken::class, OpaqueToken::class);
        $this->recipe(OAuthClientManager::class, OAuthClientManager::class, [
            $this->ref(OAuthClientStoreInterface::class),
            $this->ref(PasswordHasherInterface::class),
            $this->ref(PasswordVerifierInterface::class),
            $this->ref(ClockInterface::class),
            $this->ref(OpaqueToken::class),
            $this->app->config()->isProduction(),
        ]);
        $this->recipe(OAuthScopeResolver::class, OAuthScopeResolver::class, [
            $this->ref(OAuthClientStoreInterface::class),
            $this->ref(ConfigRepository::class),
        ]);
        $this->recipe(AuthorizationRequestValidator::class, AuthorizationRequestValidator::class, [
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(ConfigRepository::class),
        ]);
        $this->recipe(ConsentManager::class, ConsentManager::class, [
            $this->ref(OAuthConsentStoreInterface::class),
            $this->ref(AuthorizerInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(OAuthSigningKeyResolver::class, OAuthSigningKeyResolver::class, [
            $this->ref(ConfigRepository::class),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->staticRecipe(
            OAuthSigningKeySet::class,
            AuthOAuthGraphFactory::class,
            'signingKeySet',
            [$this->ref(OAuthSigningKeyResolver::class)],
        );
    }

    private function registerFoundationStores(): void
    {
        $connection = $this->authConnection();
        $stores = [
            OAuthClientStoreInterface::class => DBLayerOAuthClientStore::class,
            OAuthConsentStoreInterface::class => DBLayerOAuthConsentStore::class,
        ];
        foreach ($stores as $id => $implementation) {
            $this->recipe($id, $implementation, [
                $this->ref(DBLayerFactory::class),
                $this->ref(AuthTables::class),
                $connection,
            ]);
        }
    }
}
