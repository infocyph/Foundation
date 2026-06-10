<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final readonly class ConfigIssue
{
    public function __construct(
        public string $message,
        public string $key = '',
        public string $severity = 'error',
    ) {}
}
