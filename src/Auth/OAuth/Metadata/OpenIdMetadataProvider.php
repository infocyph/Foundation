<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Metadata;

use Infocyph\Epicrypt\Auth\Oidc\OpenIdProviderMetadata;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdSubjectType;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class OpenIdMetadataProvider
{
    public function __construct(
        private AuthorizationServerMetadata $oauth,
        private AsymmetricSigningKeySet $idTokenKeys,
        private ConfigRepository $config,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return new OpenIdProviderMetadata(
            oauth: $this->oauth->epicrypt(),
            idTokenKeys: $this->idTokenKeys,
            userInfoEndpoint: $this->endpoint(),
            subjectTypes: $this->subjectTypes(),
            scopesSupported: $this->stringList('auth.oauth.oidc.scopes_supported'),
            claimsSupported: $this->stringList('auth.oauth.oidc.claims_supported'),
        )->toArray();
    }

    private function endpoint(): string
    {
        $issuer = $this->config->get('auth.oauth.issuer');
        $route = $this->config->get('auth.oauth.oidc.userinfo_route');
        if (!is_string($issuer) || !is_string($route) || $issuer === '' || !str_starts_with($route, '/')) {
            throw new ConfigurationException('OpenID UserInfo endpoint configuration is incomplete.');
        }

        $parts = parse_url($issuer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('OpenID issuer configuration is invalid.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . $route;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $values = $this->config->get($key, []);
        if (!is_array($values) || !array_is_list($values)) {
            throw new ConfigurationException(sprintf('%s must be a list.', $key));
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new ConfigurationException(sprintf('%s must contain non-empty strings.', $key));
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /** @return list<OpenIdSubjectType> */
    private function subjectTypes(): array
    {
        $types = $this->stringList('auth.oauth.oidc.subject_types');
        $normalized = [];
        foreach ($types as $type) {
            $resolved = OpenIdSubjectType::tryFrom($type);
            if (!$resolved instanceof OpenIdSubjectType) {
                throw new ConfigurationException('OpenID subject type is invalid.');
            }
            $normalized[] = $resolved;
        }

        return $normalized;
    }
}
