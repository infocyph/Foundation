<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class RouterManager
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('router', []);
        }

        return $this->config->get('router.' . $key, $default);
    }
}
