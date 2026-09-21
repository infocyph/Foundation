<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationMethod as EpicryptMethod;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationResult;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class EpicryptOAuthClientAuthenticationAdapter
{
    public function __construct(
        private OAuthClientAuthenticator $authenticator,
        private ConfigRepository $config,
    ) {}

    public function authenticate(OAuthClientAuthentication $authentication): ?OAuthClientAuthenticationResult
    {
        return match ($authentication->method) {
            OAuthClientAuthenticationMethod::None => null,
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            OAuthClientAuthenticationMethod::ClientSecretPost => $this->authenticator->authenticateSecret(
                $authentication->clientId,
                EpicryptMethod::from($authentication->method->value),
                $authentication->secret ?? '',
            ),
            OAuthClientAuthenticationMethod::PrivateKeyJwt => $this->authenticator->authenticatePrivateKeyJwt(
                $authentication->clientId,
                $authentication->assertion ?? '',
                $this->tokenEndpointUri(),
            ),
        };
    }

    public function tokenEndpointUri(): string
    {
        $issuer = $this->config->get('auth.oauth.issuer');
        $path = $this->config->get('auth.oauth.routes.token');
        if (!is_string($issuer) || !is_string($path) || $issuer === '' || !str_starts_with($path, '/')) {
            throw new ConfigurationException('OAuth token endpoint configuration is incomplete.');
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
}
