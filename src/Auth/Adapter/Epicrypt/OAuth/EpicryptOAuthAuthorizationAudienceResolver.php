<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationAudienceResolverInterface;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClient;

/**
 * Foundation's audience request extension. Epicrypt still enforces bounds and client registration.
 */
final readonly class EpicryptOAuthAuthorizationAudienceResolver implements OAuthAuthorizationAudienceResolverInterface
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private array $parameters,
    ) {}

    public function resolve(OAuthClient $client, array $scopes): array
    {
        unset($scopes);

        if (!array_key_exists('audience', $this->parameters)) {
            return count($client->audiences) === 1 ? $client->audiences : [];
        }

        $value = $this->parameters['audience'];
        if (!is_string($value) || $value === '' || strlen($value) > 4096) {
            return [];
        }

        $audiences = preg_split('/\x20+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($audiences) || $audiences === [] || count($audiences) > 16) {
            return [];
        }

        return $audiences;
    }
}
