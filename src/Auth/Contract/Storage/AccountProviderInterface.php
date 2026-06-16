<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Account\AccountInterface;

interface AccountProviderInterface
{
    public function findById(string $id): ?AccountInterface;

    public function findByIdentifier(string $identifier): ?AccountInterface;
}
