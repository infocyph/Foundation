<?php

declare(strict_types=1);

namespace Infocyph\Foundation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ApiPreset;
use Infocyph\Foundation\Config\FoundationPreset;
use Infocyph\Foundation\Config\LocalPreset;
use Infocyph\Foundation\Config\ProductionPreset;
use Infocyph\Foundation\Facades\Facade;

final class Foundation
{
    /**
     * @param array<string, mixed> $config
     */
    public static function api(array $config = []): Application
    {
        return self::preset(new ApiPreset(), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function console(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Console, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function local(array $config = []): Application
    {
        return self::preset(new LocalPreset(), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function preset(FoundationPreset $preset, array $config = []): Application
    {
        $config['_preset'] = $preset->config();

        return self::web($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function production(array $config = []): Application
    {
        return self::preset(new ProductionPreset(), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function web(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Web, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createFor(RuntimeMode $runtimeMode, array $config): Application
    {
        $app = Application::create($config, $runtimeMode);
        Facade::setApplication($app);

        return $app;
    }
}
