<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\OTP\Contracts\ReplayStoreInterface;

final readonly class OtpReplayStore implements ReplayStoreInterface
{
    public function __construct(
        private TtlStoreInterface $ttl,
        private int $defaultTtl = 90,
    ) {}

    public function getState(string $namespace, string $binding): int|string|null
    {
        $state = $this->ttl->get($this->stateKey($namespace, $binding));

        return is_int($state) || is_string($state) ? $state : null;
    }

    public function hasConsumed(string $namespace, string $binding, string $token): bool
    {
        return (bool) $this->ttl->get($this->consumedKey($namespace, $binding, $token), false);
    }

    public function markConsumed(string $namespace, string $binding, string $token, ?int $ttl = null): void
    {
        $this->ttl->put(
            $this->consumedKey($namespace, $binding, $token),
            true,
            $ttl ?? $this->defaultTtl,
        );
    }

    public function setState(string $namespace, string $binding, int|string|null $value, ?int $ttl = null): void
    {
        $key = $this->stateKey($namespace, $binding);
        if ($value === null) {
            $this->ttl->delete($key);

            return;
        }

        $this->ttl->put($key, $value, $ttl ?? $this->defaultTtl);
    }

    private function consumedKey(string $namespace, string $binding, string $token): string
    {
        return sprintf('otp:replay:%s:%s:%s', $namespace, $binding, $token);
    }

    private function stateKey(string $namespace, string $binding): string
    {
        return sprintf('otp:state:%s:%s', $namespace, $binding);
    }
}
