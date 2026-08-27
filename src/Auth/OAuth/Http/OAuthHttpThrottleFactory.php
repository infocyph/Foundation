<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Psr\Cache\CacheItemPoolInterface;

final readonly class OAuthHttpThrottleFactory
{
    private const array ENDPOINTS = ['authorization', 'token', 'revocation', 'introspection'];

    public function __construct(
        private ConfigRepository $config,
        private OAuthAuditRecorder $audit,
    ) {}

    public function forEndpoint(string $endpoint, ?CacheItemPoolInterface $pool = null): OAuthRateLimitMiddleware
    {
        $policy = $this->policy($endpoint);
        $throttle = new ThrottleMiddleware(
            max: $policy['max'],
            window: $policy['window'],
            pool: $pool,
            scope: $policy['scope'],
        );

        return new OAuthRateLimitMiddleware($endpoint, $throttle, $this->audit);
    }

    /** @return array{max:int,window:int,scope:string} */
    public function policy(string $endpoint): array
    {
        if (!in_array($endpoint, self::ENDPOINTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported OAuth rate-limit endpoint "%s".', $endpoint));
        }

        $base = 'auth.oauth.rate_limits.' . $endpoint;

        return [
            'max' => $this->positiveInt($base . '.max'),
            'window' => $this->positiveInt($base . '.window'),
            'scope' => 'foundation.oauth.' . $endpoint,
        ];
    }

    private function positiveInt(string $key): int
    {
        $value = $this->config->get($key);
        if (!is_int($value) || $value < 1) {
            throw new \LogicException(sprintf('%s must be a positive integer.', $key));
        }

        return $value;
    }
}
