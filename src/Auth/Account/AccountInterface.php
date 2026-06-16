<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

interface AccountInterface
{
    public function id(): string;

    public function identifier(): string;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    public function passwordHash(): ?string;

    public function status(): AccountStatus;
}
