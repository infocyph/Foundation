<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Uid;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Support\GeneratesAuthIds;
use Infocyph\Foundation\Identifiers\IdentifierManager;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

final readonly class UidAuthIdGenerator implements AuthIdGeneratorInterface
{
    use GeneratesAuthIds;

    public function __construct(
        private readonly ?IdentifierManager $ids = null,
    ) {}

    protected function generate(string $key): string
    {
        if ($this->ids instanceof IdentifierManager) {
            return $this->ids->generateForAuth($key);
        }

        return $key === 'correlation'
            ? ULID::generate()
            : UUID::v7();
    }
}
