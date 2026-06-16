<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Session;

final readonly class SessionConfig
{
    public function __construct(
        public int $absoluteTtlSeconds = 3600,
        public int $recentAuthWindowSeconds = 900,
    ) {}
}
