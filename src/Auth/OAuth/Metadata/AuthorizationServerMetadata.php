<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Metadata;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationServerMetadata as EpicryptAuthorizationServerMetadata;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationMethod as EpicryptClientAuthenticationMethod;
use Infocyph\Epicrypt\Auth\OAuth\OAuthEndpointCapability;
use Infocyph\Epicrypt\Auth\OAuth\OAuthEndpointCapabilityCatalog;
use Infocyph\Epicrypt\Auth\OAuth\OAuthGrantType as EpicryptGrantType;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class AuthorizationServerMetadata
{
    public function __construct(private ConfigRepository $config) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return new EpicryptAuthorizationServerMetadata(
            issuer: $this->issuer(),
            capabilities: new OAuthEndpointCapabilityCatalog(
                endpoints: [
                    OAuthEndpointCapability::AUTHORIZATION,
                    OAuthEndpointCapability::TOKEN,
                    OAuthEndpointCapability::REVOCATION,
                    OAuthEndpointCapability::INTROSPECTION,
                    OAuthEndpointCapability::JWKS,
                    OAuthEndpointCapability::METADATA,
                ],
                grantTypes: $this->grantTypes(),
                clientAuthenticationMethods: [
                    EpicryptClientAuthenticationMethod::NONE,
                    EpicryptClientAuthenticationMethod::CLIENT_SECRET_BASIC,
                    EpicryptClientAuthenticationMethod::CLIENT_SECRET_POST,
                    EpicryptClientAuthenticationMethod::PRIVATE_KEY_JWT,
                ],
            ),
            authorizationEndpoint: $this->endpoint('authorization'),
            tokenEndpoint: $this->endpoint('token'),
            revocationEndpoint: $this->endpoint('revocation'),
            introspectionEndpoint: $this->endpoint('introspection'),
            jwksUri: $this->endpoint('jwks'),
            dpopSigningAlgorithms: [AsymmetricJwtAlgorithm::ES256],
            clientAssertionSigningAlgorithms: AsymmetricJwtAlgorithm::cases(),
        )->toArray();
    }

    private function endpoint(string $name): string
    {
        $parts = parse_url($this->issuer());
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('OAuth issuer configuration is invalid.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . $this->string('auth.oauth.routes.' . $name);
    }

    /** @return list<EpicryptGrantType> */
    private function grantTypes(): array
    {
        $configured = $this->config->get('auth.oauth.grants', []);
        if (!is_array($configured) || !array_is_list($configured)) {
            throw new \LogicException('OAuth grant configuration is invalid.');
        }

        $grants = [];
        foreach ($configured as $grant) {
            if (!is_string($grant) || !($resolved = EpicryptGrantType::tryFrom($grant)) instanceof EpicryptGrantType) {
                throw new \LogicException('OAuth grant configuration is invalid.');
            }
            $grants[] = $resolved;
        }

        return $grants;
    }

    private function issuer(): string
    {
        return $this->string('auth.oauth.issuer');
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);
        if (!is_string($value) || $value === '') {
            throw new \LogicException('OAuth metadata configuration is incomplete.');
        }

        return $value;
    }
}
