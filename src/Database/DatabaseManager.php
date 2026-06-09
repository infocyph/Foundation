<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class DatabaseManager
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('database', []);
        }

        return $this->config->get('database.' . $key, $default);
    }
}
