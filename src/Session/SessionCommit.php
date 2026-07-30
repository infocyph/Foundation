<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

final readonly class SessionCommit
{
    public function __construct(
        public bool $persisted,
        public ?string $id,
    ) {}
}
