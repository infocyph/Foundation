<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Scope;

use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class OAuthScopeResolver
{
    private const int MAX_REQUESTED_SCOPES = 64;

    public function __construct(
        private OAuthClientStoreInterface $clients,
        private ConfigRepository $config,
    ) {}

    /**
     * @param list<string> $previous
     * @param list<string> $requested
     * @return list<string>
     */
    public function narrow(array $previous, array $requested): array
    {
        if ($requested === []) {
            return $previous;
        }

        return $this->subset($requested, $previous, self::MAX_REQUESTED_SCOPES, 'scope');
    }

    /**
     * @param list<string> $requestedScopes
     * @param list<string> $requestedAudiences
     */
    public function resolve(
        OAuthClient $client,
        array $requestedScopes,
        array $requestedAudiences,
    ): OAuthScopeSelection {
        $scopes = $this->subset(
            $requestedScopes,
            $this->clients->scopes($client->clientId),
            self::MAX_REQUESTED_SCOPES,
            'scope',
        );
        $audiences = $this->subset(
            $requestedAudiences,
            $client->audiences,
            16,
            'audience',
        );

        return new OAuthScopeSelection(
            scopes: $scopes,
            audiences: $audiences,
            permissions: $this->mappedPermissions($scopes),
        );
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    private function mappedPermissions(array $scopes): array
    {
        $configured = $this->config->get('auth.oauth.scope_permissions', []);
        if (!is_array($configured)) {
            return [];
        }

        $permissions = [];
        foreach ($scopes as $scope) {
            $permission = $configured[$scope] ?? null;
            if (is_string($permission) && $permission !== '') {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    /**
     * @param list<string> $requested
     * @param list<string> $allowed
     * @return list<string>
     */
    private function subset(array $requested, array $allowed, int $maximum, string $name): array
    {
        if ($requested === [] || count($requested) > $maximum) {
            throw new \InvalidArgumentException(sprintf('OAuth %s request is missing or exceeds policy limits.', $name));
        }

        $allowedSet = array_fill_keys($allowed, true);
        $selected = [];
        foreach ($requested as $value) {
            if ($value === '' || !isset($allowedSet[$value]) || isset($selected[$value])) {
                throw new \InvalidArgumentException(sprintf('OAuth %s request exceeds client policy.', $name));
            }
            $selected[$value] = true;
        }

        return array_keys($selected);
    }
}
