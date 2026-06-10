<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;

final readonly class RouterManager
{
    public function __construct(
        private ConfigRepository $config,
        private WebrickRouterFactory $factory,
        private RoutePresetRegistrar $presets,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('router', []);
        }

        return $this->config->get('router.' . $key, $default);
    }

    public function registerAuthMiddleware(): void
    {
        $this->presets->register();
    }

    public function apiAuth(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->apiAuth($this->router(), $callback, $prefix, $domain, $namePrefix);
    }

    public function authMfa(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->authMfa($this->router(), $callback, $prefix, $domain, $namePrefix);
    }

    public function authVerified(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->authVerified($this->router(), $callback, $prefix, $domain, $namePrefix);
    }

    public function authWeb(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->authWeb($this->router(), $callback, $prefix, $domain, $namePrefix);
    }

    public function dispatch(Request $request, ?ErrorHandler $errorHandler = null): Response
    {
        return $this->kernel($errorHandler)->handle($request);
    }

    public function groupWithPreset(
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->group($this->router(), $preset, $callback, $prefix, $domain, $namePrefix);
    }

    public function kernel(?ErrorHandler $errorHandler = null): RouterKernel
    {
        $this->presets->register();

        return $this->factory->kernel($errorHandler);
    }

    public function router(): Registrar
    {
        $this->presets->register();

        return $this->factory->router();
    }

    public function routes(): Collection
    {
        $this->presets->register();

        return $this->factory->routes();
    }

    public function __call(string $method, array $arguments): mixed
    {
        $router = $this->router();

        if (!is_callable([$router, $method])) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                $router::class,
                $method,
            ));
        }

        return $router->{$method}(...$arguments);
    }
}
