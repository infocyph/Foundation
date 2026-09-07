<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Config\ConfigRepository;

final class AuthRuntimeGraphFactory
{
    public static function manager(
        AuthServices $services,
        ConfigRepository $config,
        AuthDriverResolver $drivers,
    ): AuthManager {
        return new AuthManager(
            services: $services,
            config: $config,
            drivers: $drivers->summary(),
        );
    }
}
