<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Metadata;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class AuthorizationServerMetadata
{
    public function __construct(private ConfigRepository $config) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $issuer = $this->string('auth.oauth.issuer');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $this->endpoint($issuer, 'authorization'),
            'token_endpoint' => $this->endpoint($issuer, 'token'),
            'jwks_uri' => $this->endpoint($issuer, 'jwks'),
            'revocation_endpoint' => $this->endpoint($issuer, 'revocation'),
            'introspection_endpoint' => $this->endpoint($issuer, 'introspection'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => $this->stringList('auth.oauth.grants'),
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    private function endpoint(string $issuer, string $name): string
    {
        $parts = parse_url($issuer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('OAuth issuer configuration is invalid.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $issuerPath = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $route = $this->string('auth.oauth.routes.' . $name);

        return $origin . $issuerPath . '/' . ltrim($route, '/');
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);
        if (!is_string($value) || $value === '') {
            throw new \LogicException('OAuth metadata configuration is incomplete.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key, []);
        if (!is_array($value) || !array_is_list($value)) {
            throw new \LogicException('OAuth metadata configuration is invalid.');
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \LogicException('OAuth metadata configuration is invalid.');
            }
            $result[] = $item;
        }

        return $result;
    }
}
