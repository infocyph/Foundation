<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class ValidationManager
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('validation', []);
        }

        return $this->config->get('validation.' . $key, $default);
    }
}
