<?php

declare(strict_types=1);

namespace Infocyph\Foundation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\FoundationPreset;

final class Foundation
{
    /** @param array<string, mixed> $config */
    public static function web(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Web, $config);
    }

    /** @param array<string, mixed> $config */
    public static function cli(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Cli, $config);
    }

    /** @param array<string, mixed> $config */
    public static function worker(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Worker, $config);
    }

    /** @param array<string, mixed> $config */
    public static function scheduler(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Scheduler, $config);
    }

    /**
     * Apply an environment/application preset independently from the selected runtime.
     *
     * @param array<string, mixed> $config
     */
    public static function preset(RuntimeMode $runtime, FoundationPreset $preset, array $config = []): Application
    {
        $config['_preset'] = $preset->config();

        return self::createFor($runtime, $config);
    }

    /** @param array<string, mixed> $config */
    private static function createFor(RuntimeMode $runtime, array $config): Application
    {
        return Application::create($config, $runtime);
    }
}
