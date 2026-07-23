<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console;

use Closure;
use Infocyph\Console\Application as ConsoleApplication;
use Infocyph\Console\Command\CommandContract;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\Command\AppReadyCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaInstallCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaStatusCommand;
use Infocyph\Foundation\Console\Command\ConfigCacheCommand;
use Infocyph\Foundation\Console\Command\ConfigClearCommand;
use Infocyph\Foundation\Console\Command\RouteCacheCommand;
use Infocyph\Foundation\Console\Command\RouteClearCommand;

final class FoundationConsole
{
    private function __construct() {}

    /**
     * @return list<class-string<CommandContract>>
     */
    public static function commands(): array
    {
        return [
            AppReadyCommand::class,
            AuthSchemaStatusCommand::class,
            AuthSchemaInstallCommand::class,
            ConfigCacheCommand::class,
            ConfigClearCommand::class,
            RouteCacheCommand::class,
            RouteClearCommand::class,
        ];
    }

    /**
     * @param Closure(?string): Application $applicationFactory
     * @param list<class-string<CommandContract>> $commands
     */
    public static function create(
        Closure $applicationFactory,
        string $name = 'foundation',
        string $version = 'dev',
        array $commands = [],
    ): ConsoleApplication {
        $runtime = new FoundationConsoleRuntime($applicationFactory);

        return ConsoleApplication::configure()
            ->name($name)
            ->version($version)
            ->commands([...self::commands(), ...$commands])
            ->containerProvider($runtime)
            ->configurationProvider($runtime)
            ->build();
    }
}
