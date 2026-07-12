<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use DateTimeInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as WebrickRouter;
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

    /**
     * @param list<mixed> $arguments
     */
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

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function apiAuth(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->preset('apiAuth', $callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function authMfa(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->preset('authMfa', $callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function authVerified(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->preset('authVerified', $callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function authWeb(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->preset('authWeb', $callback, $prefix, $domain, $namePrefix);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('router', []);
        }

        return $this->config->get('router.' . $key, $default);
    }

    public function dispatch(Request $request, ?ErrorHandler $errorHandler = null): Response
    {
        return $this->kernel($errorHandler)->handle($request);
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
        $this->presets->group($this->router(), $preset, $callback, $prefix, $domain, $namePrefix);
    }

    public function kernel(?ErrorHandler $errorHandler = null): RouterKernel
    {
        return $this->registered(fn() => $this->factory->kernel($errorHandler));
    }

    public function registerAuthMiddleware(): void
    {
        $this->presets->register();
    }

    public function router(): Registrar
    {
        return $this->registered($this->factory->router(...));
    }

    public function routes(): Collection
    {
        return $this->registered($this->factory->routes(...));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function signedUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        $this->router();

        return WebrickRouter::signedUrlFor($name, $params, $query, $ttl, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function temporaryUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        int $ttl = 900,
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        $this->router();

        return WebrickRouter::temporaryUrlFor($name, $params, $query, $ttl, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function temporaryUrlUntil(
        string $name,
        DateTimeInterface $expiresAt,
        array $params = [],
        array $query = [],
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        $this->router();

        return WebrickRouter::temporaryUrlUntil($name, $expiresAt, $params, $query, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function urlFor(string $name, array $params = [], array $query = [], bool $absolute = false): string
    {
        $this->router();

        return WebrickRouter::urlFor($name, $params, $query, $absolute);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    private function preset(
        string $method,
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->presets->{$method}($this->router(), $callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    private function registered(callable $callback): mixed
    {
        $this->presets->register();

        return $callback();
    }
}
