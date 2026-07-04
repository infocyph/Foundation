<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Filesystem\PathManager;

final readonly class RouteFileLoader
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        private PathManager $paths,
        private RouterManager $router,
        private array $files = ['web.php', 'api.php', 'auth.php'],
    ) {}

    public function load(): void
    {
        foreach ($this->files as $file) {
            $path = $this->paths->routes($file);

            if (!is_file($path)) {
                continue;
            }

            $router = $this->router;

            require $path;
        }
    }
}
