<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\DBLayer\DB;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationCodeStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthConsentStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAccessTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthJwkSetProvider;
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
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationCodeStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthConsentStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenCoordinator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\SessionConfig;

final readonly class AuthOAuthRegistrar extends AbstractAuthRegistrar
{
    public function enabled(): bool
    {
        return $this->boolConfig('auth.oauth.enabled', false);
    }

    public function register(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->requirePackage(DB::class, 'infocyph/dblayer', 'database');
        $this->requirePackage(AsymmetricJwt::class, 'infocyph/epicrypt', 'crypto');
        $this->requirePackage(\Infocyph\CacheLayer\Cache\Cache::class, 'infocyph/cachelayer', 'cache');
        $this->registerStores();
        $this->registerCrypto();
        $this->registerProtocolServices();
    }

    private function authConnection(): ?string
    {
        $connection = $this->app->config()->get('database.default');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function registerCrypto(): void
    {
        $this->recipe(OpaqueToken::class, OpaqueToken::class);
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
        $this->recipe(JwkSetProviderInterface::class, EpicryptOAuthJwkSetProvider::class, [
            $this->ref(OAuthSigningKeySet::class),
        ]);
        $this->recipe(OAuthAccessTokenServiceInterface::class, EpicryptOAuthAccessTokenService::class, [
            $this->ref(OAuthSigningKeySet::class),
            $this->intConfig('auth.oauth.access_token_ttl', 300),
            30,
        ]);
    }

    private function registerProtocolServices(): void
    {
        $this->recipe(OAuthAuditRecorder::class, OAuthAuditRecorder::class, [
            $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
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
        ]);
        $this->recipe(ConsentManager::class, ConsentManager::class, [
            $this->ref(OAuthConsentStoreInterface::class),
            $this->ref(AuthorizerInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(AuthorizationCodeManager::class, AuthorizationCodeManager::class, [
            $this->ref(OAuthAuthorizationCodeStoreInterface::class),
            $this->ref(OAuthAuthorizationStoreInterface::class),
            $this->ref(AuthorizerInterface::class),
            $this->ref(ClockInterface::class),
            $this->ref(OpaqueToken::class),
            $this->intConfig('auth.oauth.authorization_code_ttl', 60),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthRefreshTokenCoordinator::class, OAuthRefreshTokenCoordinator::class, [
            $this->ref(OAuthRefreshTokenStoreInterface::class),
            $this->ref(OAuthAuthorizationStoreInterface::class),
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(AccountProviderInterface::class),
            $this->ref(ClockInterface::class),
            $this->ref(OpaqueToken::class),
            $this->intConfig('auth.oauth.refresh_token_ttl', 1209600),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthAccessTokenValidator::class, OAuthAccessTokenValidator::class, [
            $this->ref(OAuthAccessTokenServiceInterface::class),
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthAuthorizationStoreInterface::class),
            $this->ref(OAuthAccessRevocationStoreInterface::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(AccountProviderInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(OAuthTokenManager::class, OAuthTokenManager::class, [
            $this->ref(OAuthClientManager::class),
            $this->ref(AuthorizationCodeManager::class),
            $this->ref(OAuthAuthorizationStoreInterface::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(OAuthAccessTokenServiceInterface::class),
            $this->ref(OAuthRefreshTokenCoordinator::class),
            $this->ref(AccountProviderInterface::class),
            $this->ref(ClockInterface::class),
            $this->ref(OAuthSigningKeySet::class),
            $this->intConfig('auth.oauth.access_token_ttl', 300),
        ]);
        $this->recipe(OAuthRevocationManager::class, OAuthRevocationManager::class, [
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthAccessTokenServiceInterface::class),
            $this->ref(OAuthAccessRevocationStoreInterface::class),
            $this->ref(OAuthRefreshTokenCoordinator::class),
            $this->ref(ClockInterface::class),
            $this->ref(OAuthAuditRecorder::class),
        ]);
        $this->recipe(OAuthIntrospectionManager::class, OAuthIntrospectionManager::class, [
            $this->ref(OAuthClientManager::class),
            $this->ref(OAuthAccessTokenValidator::class),
            $this->ref(OAuthRefreshTokenStoreInterface::class),
            $this->ref(OAuthAuthorizationStoreInterface::class),
            $this->ref(OAuthScopeResolver::class),
            $this->ref(AccountProviderInterface::class),
            $this->ref(ClockInterface::class),
            $this->ref(OpaqueToken::class),
        ]);
        $this->recipe(AuthorizationServerMetadata::class, AuthorizationServerMetadata::class, [
            $this->ref(ConfigRepository::class),
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
        ]);
        $this->recipe(OAuthAuthorizationController::class, OAuthAuthorizationController::class, [
            $this->ref(OAuthHttpHandler::class),
            $this->ref(CurrentPrincipalContext::class),
            $this->ref(SessionConfig::class),
        ]);
    }

    private function registerStores(): void
    {
        $connection = $this->authConnection();
        $stores = [
            OAuthClientStoreInterface::class => DBLayerOAuthClientStore::class,
            OAuthAuthorizationCodeStoreInterface::class => DBLayerOAuthAuthorizationCodeStore::class,
            OAuthConsentStoreInterface::class => DBLayerOAuthConsentStore::class,
            OAuthAuthorizationStoreInterface::class => DBLayerOAuthAuthorizationStore::class,
            OAuthRefreshTokenStoreInterface::class => DBLayerOAuthRefreshTokenStore::class,
            OAuthAccessRevocationStoreInterface::class => DBLayerOAuthAccessRevocationStore::class,
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
