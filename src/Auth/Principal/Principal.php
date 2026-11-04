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

    #[\Override]
    public function accountId(): ?string
    {
        return $this->accountId;
    }

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function metadata(): array
    {
        return $this->metadata;
    }

    #[\Override]
    public function type(): PrincipalType
    {
        return $this->type;
    }
}
