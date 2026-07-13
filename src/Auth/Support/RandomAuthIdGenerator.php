<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;

final class RandomAuthIdGenerator implements AuthIdGeneratorInterface
{
    use GeneratesAuthIds;

    protected function generate(string $key): string
    {
        return sprintf('%s_%s', $this->prefixFor($key), bin2hex(random_bytes(16)));
    }

    private function prefixFor(string $key): string
    {
        return match ($key) {
            'account' => 'acct',
            'audit_event' => 'evt',
            'challenge' => 'chl',
            'correlation' => 'corr',
            'credential' => 'cred',
            'device' => 'dev',
            'grant' => 'grant',
            'permission' => 'perm',
            'role' => 'role',
            'session' => 'sess',
            default => throw new \LogicException(sprintf('Unsupported auth identifier key "%s".', $key)),
        };
    }
}
