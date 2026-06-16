<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

interface PrincipalInterface
{
    public function accountId(): ?string;

    public function id(): string;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    public function type(): PrincipalType;
}
