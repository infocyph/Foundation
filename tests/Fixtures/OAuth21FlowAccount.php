<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;

final readonly class OAuth21FlowAccount implements AccountInterface
{
    public function __construct(private string $id) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function identifier(): string
    {
        return 'account@example.test';
    }

    #[\Override]
    public function metadata(): array
    {
        return [];
    }

    #[\Override]
    public function passwordHash(): ?string
    {
        return null;
    }

    #[\Override]
    public function status(): AccountStatus
    {
        return AccountStatus::ACTIVE;
    }
}
