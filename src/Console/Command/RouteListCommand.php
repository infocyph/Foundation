<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\RouteCacheManager;

final class RouteListCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly RouteCacheManager $routes) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('route:list')
            ->description('List registered HTTP routes, handlers, and middleware.')
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $routes = $this->routes->routes()->all();
        } catch (\Throwable $exception) {
            $this->io()->error('route:list failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $report = [];
        foreach ($routes as $route) {
            $middleware = [];
            foreach ($route->getMiddlewares() as $entry) {
                $middleware[] = is_string($entry) ? $entry : $entry::class;
            }
            $report[] = [
                'method' => $route->getMethod(),
                'path' => $route->getPath(),
                'name' => $route->getName(),
                'domain' => $route->getDomain(),
                'handler' => $route->getHandlerId(),
                'middleware' => $middleware,
            ];
        }

        if ($this->options()->bool('json')) {
            $this->report(['routes' => $report]);

            return ExitCode::SUCCESS;
        }

        $rows = [];
        foreach ($report as $route) {
            $rows[] = [
                $route['method'],
                $route['path'],
                $route['name'] ?? '-',
                $route['handler'],
                implode(', ', $route['middleware']),
            ];
        }
        $this->io()->table(['Method', 'Path', 'Name', 'Handler', 'Middleware'], $rows);

        return ExitCode::SUCCESS;
    }
}
