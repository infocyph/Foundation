<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Role;

final readonly class Role
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $metadata = [],
    ) {}
}
