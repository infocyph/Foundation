<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptAsymmetricSigningKeyResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\OAuthProtectionKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final class AuthOAuthGraphFactory
{
    public static function authorizationCodeKeys(OAuthProtectionKeyResolver $resolver): KeyRing
    {
        return $resolver->authorizationCodeKeys();
    }

    public static function endpointUri(ConfigRepository $config, string $route): string
    {
        $issuer = $config->get('auth.oauth.issuer');
        $path = $config->get('auth.oauth.routes.' . $route);
        if (!is_string($issuer) || !is_string($path) || $issuer === '' || !str_starts_with($path, '/')) {
            throw new ConfigurationException('OAuth endpoint configuration is incomplete.');
        }

        $parts = parse_url($issuer);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower((string) $parts['scheme']) !== 'https'
        ) {
            throw new ConfigurationException('OAuth issuer must be an absolute HTTPS URI.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . $path;
    }

    public static function epicryptSigningKeySet(OAuthSigningKeySet $keys): AsymmetricSigningKeySet
    {
        return $keys->epicrypt;
    }

    public static function openIdSigningKeySet(ConfigRepository $config): AsymmetricSigningKeySet
    {
        $issuer = $config->get('auth.oauth.issuer');
        if (!is_string($issuer) || $issuer === '') {
            throw new ConfigurationException('OpenID issuer configuration is incomplete.');
        }

        return new EpicryptAsymmetricSigningKeyResolver($config)->resolve(
            'auth.oauth.oidc.signing',
            $issuer,
            KeyPurpose::OIDC_ID_TOKEN_SIGNING,
        );
    }

    public static function refreshTokenKeys(OAuthProtectionKeyResolver $resolver): KeyRing
    {
        return $resolver->refreshTokenKeys();
    }

    public static function signingKeySet(OAuthSigningKeyResolver $resolver): OAuthSigningKeySet
    {
        return $resolver->resolve();
    }
}
