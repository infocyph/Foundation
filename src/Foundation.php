<?php

declare(strict_types=1);

namespace Infocyph\Foundation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ApiPreset;
use Infocyph\Foundation\Config\ConfigMerger;
use Infocyph\Foundation\Config\FoundationPreset;
use Infocyph\Foundation\Config\LocalPreset;
use Infocyph\Foundation\Config\ProductionPreset;
use Infocyph\Foundation\Facades\Facade;

final class Foundation
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config = []): Application
    {
        $app = Application::create($config);
        Facade::setApplication($app);

        return $app;
    }

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
    public static function local(array $config = []): Application
    {
        return self::preset(new LocalPreset(), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function preset(FoundationPreset $preset, array $config = []): Application
    {
        return self::create(ConfigMerger::merge(
            $preset->config(),
            $config,
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function production(array $config = []): Application
    {
        return self::preset(new ProductionPreset(), $config);
    }
}
