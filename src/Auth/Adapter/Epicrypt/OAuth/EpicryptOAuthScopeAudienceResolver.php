<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Auth\OAuth\OAuthClient as EpicryptOAuthClient;
use Infocyph\Epicrypt\Auth\OAuth\OAuthScopeAudienceResolverInterface;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class EpicryptOAuthScopeAudienceResolver implements OAuthScopeAudienceResolverInterface
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthScopeResolver $scopes,
        private ConfigRepository $config,
    ) {}

    public function resolve(EpicryptOAuthClient $client, array $scopes): array
    {
        $foundation = $this->clients->enabled($client->clientId);
        if (!$foundation instanceof OAuthClient) {
            return [];
        }

        $audiences = $this->mappedAudiences($scopes);
        if ($audiences === [] && count($foundation->audiences) === 1) {
            $audiences = $foundation->audiences;
        }
        if ($audiences === []) {
            return [];
        }

        try {
            /** @var list<string> $audiences */
            return $this->scopes->resolve($foundation, $scopes, $audiences)->audiences;
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /** @param list<string> $scopes @return list<string> */
    /** @param list<string> $scopes @return list<string> */
    private function mappedAudiences(array $scopes): array
    {
        $mapping = $this->config->get('auth.oauth.scope_audiences', []);
        if (!is_array($mapping) || array_is_list($mapping)) {
            return [];
        }

        $selected = [];
        foreach ($scopes as $scope) {
            $configured = $mapping[$scope] ?? null;
            $values = is_string($configured) ? [$configured] : $configured;
            if (!is_array($values) || !array_is_list($values)) {
                continue;
            }
            foreach ($values as $audience) {
                if (is_string($audience) && $audience !== '') {
                    $selected[$audience] = true;
                }
            }
        }

        return array_values(array_keys($selected));
    }
}
