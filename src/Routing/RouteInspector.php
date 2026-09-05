<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;

final readonly class RouteInspector
{
    public function __construct(private Application $application) {}

    public function routes(?string $routes = null): Collection
    {
        $config = $this->application->config()->all();
        $config['_config_cache'] = false;

        if ($routes !== null && trim($routes) !== '') {
            $router = is_array($config['router'] ?? null) ? $config['router'] : [];
            $router['files'] = self::routeFiles($routes);
            $config['router'] = $router;
        }

        $application = Foundation::web($config);
        $registrar = $application->make(Registrar::class);
        $application->make(RouteFileLoader::class)->load($registrar);

        return $application->make(Collection::class);
    }

    /** @return list<string> */
    private static function routeFiles(string $routes): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $routes)),
            static fn(string $route): bool => $route !== '',
        ));
    }
}
