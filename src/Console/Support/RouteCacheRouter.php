<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\RoutePresetRegistrar;
use Infocyph\Webrick\Router\Definition\Registrar;

/**
 * Route-file compatibility wrapper around RouteCache's temporary registrar.
 */
final readonly class RouteCacheRouter
{
    public function __construct(
        private Registrar $registrar,
        private RoutePresetRegistrar $presets,
        private ConfigRepository $config,
    ) {}

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($this->presets->invokeNamed($this->registrar, $method, $arguments)) {
            return null;
        }

        if (!is_callable([$this->registrar, $method])) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                $this->registrar::class,
                $method,
            ));
        }

        return $this->registrar->{$method}(...$arguments);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null || $key === ''
            ? $this->config->get('router', [])
            : $this->config->get('router.' . $key, $default);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function groupWithPreset(
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->group($this->registrar, $preset, $callback, $prefix, $domain, $namePrefix);
    }

    public function router(): Registrar
    {
        return $this->registrar;
    }
}
