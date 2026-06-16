<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

final readonly class Principal implements PrincipalInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $id,
        private PrincipalType $type = PrincipalType::ACCOUNT,
        private ?string $accountId = null,
        private array $metadata = [],
    ) {}

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function type(): PrincipalType
    {
        return $this->type;
    }
}
