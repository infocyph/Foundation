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
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
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
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

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
        $this->singleton(OpaqueToken::class, fn() => new OpaqueToken());
        $this->singleton(OAuthSigningKeyResolver::class, fn() => new OAuthSigningKeyResolver($this->app->config()));
        $this->singleton(OAuthSigningKeySet::class, fn() => $this->service(OAuthSigningKeyResolver::class)->resolve());
        $this->singleton(JwkSetProviderInterface::class, fn() => new EpicryptOAuthJwkSetProvider(
            $this->service(OAuthSigningKeySet::class),
        ));
        $this->singleton(OAuthAccessTokenServiceInterface::class, fn() => new EpicryptOAuthAccessTokenService(
            keys: $this->service(OAuthSigningKeySet::class),
            maximumLifetimeSeconds: $this->intConfig('auth.oauth.access_token_ttl', 300),
            leewaySeconds: 30,
        ));
    }

    private function registerProtocolServices(): void
    {
        $this->singleton(OAuthClientManager::class, fn() => new OAuthClientManager(
            clients: $this->service(OAuthClientStoreInterface::class),
            hasher: $this->service(PasswordHasherInterface::class),
            verifier: $this->service(PasswordVerifierInterface::class),
            clock: $this->service(ClockInterface::class),
            tokens: $this->service(OpaqueToken::class),
            production: $this->app->config()->isProduction(),
        ));
        $this->singleton(OAuthScopeResolver::class, fn() => new OAuthScopeResolver(
            $this->service(OAuthClientStoreInterface::class),
            $this->app->config(),
        ));
        $this->singleton(AuthorizationRequestValidator::class, fn() => new AuthorizationRequestValidator(
            $this->service(OAuthClientManager::class),
            $this->service(OAuthScopeResolver::class),
        ));
        $this->singleton(ConsentManager::class, fn() => new ConsentManager(
            $this->service(OAuthConsentStoreInterface::class),
            $this->service(AuthorizerInterface::class),
            $this->service(ClockInterface::class),
        ));
        $this->singleton(AuthorizationCodeManager::class, fn() => new AuthorizationCodeManager(
            codes: $this->service(OAuthAuthorizationCodeStoreInterface::class),
            authorizations: $this->service(OAuthAuthorizationStoreInterface::class),
            authorizer: $this->service(AuthorizerInterface::class),
            clock: $this->service(ClockInterface::class),
            tokens: $this->service(OpaqueToken::class),
            ttlSeconds: $this->intConfig('auth.oauth.authorization_code_ttl', 60),
        ));
        $this->singleton(OAuthRefreshTokenCoordinator::class, fn() => new OAuthRefreshTokenCoordinator(
            refreshTokens: $this->service(OAuthRefreshTokenStoreInterface::class),
            authorizations: $this->service(OAuthAuthorizationStoreInterface::class),
            clients: $this->service(OAuthClientManager::class),
            scopes: $this->service(OAuthScopeResolver::class),
            accounts: $this->service(AccountProviderInterface::class),
            clock: $this->service(ClockInterface::class),
            tokens: $this->service(OpaqueToken::class),
            ttlSeconds: $this->intConfig('auth.oauth.refresh_token_ttl', 1209600),
        ));
        $this->singleton(OAuthAccessTokenValidator::class, fn() => new OAuthAccessTokenValidator(
            tokens: $this->service(OAuthAccessTokenServiceInterface::class),
            clients: $this->service(OAuthClientManager::class),
            authorizations: $this->service(OAuthAuthorizationStoreInterface::class),
            revocations: $this->service(OAuthAccessRevocationStoreInterface::class),
            scopes: $this->service(OAuthScopeResolver::class),
            accounts: $this->service(AccountProviderInterface::class),
            clock: $this->service(ClockInterface::class),
        ));
        $this->singleton(OAuthTokenManager::class, fn() => new OAuthTokenManager(
            clients: $this->service(OAuthClientManager::class),
            codes: $this->service(AuthorizationCodeManager::class),
            authorizations: $this->service(OAuthAuthorizationStoreInterface::class),
            scopes: $this->service(OAuthScopeResolver::class),
            accessTokens: $this->service(OAuthAccessTokenServiceInterface::class),
            refreshTokens: $this->service(OAuthRefreshTokenCoordinator::class),
            accounts: $this->service(AccountProviderInterface::class),
            clock: $this->service(ClockInterface::class),
            keys: $this->service(OAuthSigningKeySet::class),
            accessTokenTtl: $this->intConfig('auth.oauth.access_token_ttl', 300),
        ));
        $this->singleton(OAuthRevocationManager::class, fn() => new OAuthRevocationManager(
            clients: $this->service(OAuthClientManager::class),
            accessTokens: $this->service(OAuthAccessTokenServiceInterface::class),
            revocations: $this->service(OAuthAccessRevocationStoreInterface::class),
            refreshTokens: $this->service(OAuthRefreshTokenCoordinator::class),
            clock: $this->service(ClockInterface::class),
        ));
        $this->singleton(OAuthIntrospectionManager::class, fn() => new OAuthIntrospectionManager(
            clients: $this->service(OAuthClientManager::class),
            accessTokens: $this->service(OAuthAccessTokenValidator::class),
            refreshTokens: $this->service(OAuthRefreshTokenStoreInterface::class),
            authorizations: $this->service(OAuthAuthorizationStoreInterface::class),
            scopes: $this->service(OAuthScopeResolver::class),
            accounts: $this->service(AccountProviderInterface::class),
            clock: $this->service(ClockInterface::class),
            opaqueTokens: $this->service(OpaqueToken::class),
        ));
        $this->singleton(AuthorizationServerMetadata::class, fn() => new AuthorizationServerMetadata($this->app->config()));
        $this->singleton(OAuthManager::class, fn() => new OAuthManager(
            authorizationRequests: $this->service(AuthorizationRequestValidator::class),
            consents: $this->service(ConsentManager::class),
            authorizationCodes: $this->service(AuthorizationCodeManager::class),
            tokens: $this->service(OAuthTokenManager::class),
            revocations: $this->service(OAuthRevocationManager::class),
            introspection: $this->service(OAuthIntrospectionManager::class),
            metadata: $this->service(AuthorizationServerMetadata::class),
            jwks: $this->service(JwkSetProviderInterface::class),
            clients: $this->service(OAuthClientManager::class),
        ));
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
            $this->singleton($id, fn() => new $implementation(
                $this->service(DBLayerFactory::class),
                $this->service(AuthTables::class),
                $connection,
            ));
        }
    }
}
