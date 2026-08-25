<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Uid;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Support\GeneratesAuthIds;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\UID\Id;

final readonly class UidAuthIdGenerator implements AuthIdGeneratorInterface
{
    use GeneratesAuthIds;

    public function __construct(private ConfigRepository $config) {}

    protected function generate(string $key): string
    {
        $default = $key === 'correlation' ? 'ulid' : 'uuid7';
        $driver = $this->config->getString('ids.auth.' . $key, $default) ?? $default;
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'ulid' => Id::ulid(),
            'uuid7', 'uuid' => Id::uuid7(),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported auth ID driver "%s" for ids.auth.%s; use uuid7 or ulid.',
                $driver,
                $key,
            )),
        };
    }
}
