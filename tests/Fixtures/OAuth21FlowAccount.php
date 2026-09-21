<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;

final readonly class OAuth21FlowAccount implements AccountInterface
{
    public function __construct(private string $id) {}

    public function id(): string
    {
        return $this->id;
    }

    public function identifier(): string
    {
        return 'account@example.test';
    }

    public function metadata(): array
    {
        return [];
    }

    public function passwordHash(): ?string
    {
        return null;
    }

    public function status(): AccountStatus
    {
        return AccountStatus::ACTIVE;
    }
}
