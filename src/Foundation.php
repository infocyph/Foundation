<?php

declare(strict_types=1);

namespace Infocyph\Foundation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\FoundationPreset;

/**
 * Public factory for runtime-specific Foundation applications.
 *
 * @api
 */
final class Foundation
{
    /**
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public static function cli(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Cli, $config);
    }

    /**
     * Apply an environment/application preset independently from the selected runtime.
     *
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public static function preset(RuntimeMode $runtime, FoundationPreset $preset, array $config = []): Application
    {
        $config['_preset'] = $preset->config();

        return self::createFor($runtime, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public static function scheduler(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Scheduler, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public static function web(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Web, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public static function worker(array $config = []): Application
    {
        return self::createFor(RuntimeMode::Worker, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @throws \Exception
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private static function createFor(RuntimeMode $runtime, array $config): Application
    {
        return Application::create($config, $runtime);
    }
}
