<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;

final readonly class RouteFileLoader
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        private PathManager $paths,
        private ConfigRepository $config,
        private Registrar $router,
        private RoutePresetRegistrar $presets,
        private OAuthRouteRegistrar $oauth,
        private array $files = ['web.php', 'api.php', 'auth.php'],
    ) {}

    public function load(): void
    {
        $this->presets->register();
        $this->oauth->register($this->router);

        foreach ($this->files as $file) {
            $path = $this->paths->routes($file);

            if (!is_file($path)) {
                continue;
            }

            $router = $this->router;
            $presets = $this->presets;

            require $path;
        }

        $this->loadAttributeRoutes();
    }

    /**
     * @return list<class-string>
     */
    private function attributeClasses(mixed $classes): array
    {
        $resolved = [];

        foreach (ValueNormalizer::stringList($classes) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            /** @var class-string $class */
            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private function attributeDirectories(mixed $directories): array
    {
        if (!is_array($directories)) {
            return [];
        }

        $resolved = [];

        foreach ($directories as $namespace => $path) {
            if (!is_string($namespace) || !is_string($path) || $namespace === '' || $path === '') {
                continue;
            }

            $resolved[$namespace] = $path;
        }

        return $resolved;
    }

    private function loadAttributeRoutes(): void
    {
        $attributes = ValueNormalizer::associativeArray($this->config->get('router.attributes', []));
        if (!ValueNormalizer::bool($attributes['enabled'] ?? false, false)) {
            return;
        }

        $classes = $this->attributeClasses($attributes['classes'] ?? []);

        if ($classes !== []) {
            AttributeRouteLoader::register($this->router, $classes);
        }

        $directories = $this->attributeDirectories($attributes['directories'] ?? []);
        if ($directories === []) {
            $directories = [
                'App\\Http\\Controllers\\' => $this->paths->app('Http/Controllers'),
            ];
        }

        $filter = ValueNormalizer::bool($attributes['controller_file_filter'] ?? true, true)
            ? AttributeRouteLoader::controllerFileFilter()
            : null;

        AttributeRouteLoader::registerFromDirs($this->router, $directories, $filter);
    }
}
