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
    /** @var array<string, string> */
    private const array NAMED_PRESETS = [
        'apiAuth' => 'api-auth',
        'authMfa' => 'mfa-auth',
        'authVerified' => 'verified-auth',
        'authWeb' => 'web-auth',
    ];

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
        if (isset(self::NAMED_PRESETS[$method])) {
            $callback = $arguments[0] ?? null;
            if (!$callback instanceof Closure) {
                throw new \InvalidArgumentException(sprintf('Route preset "%s" requires a closure callback.', $method));
            }

            if (count($arguments) > 4) {
                throw new \InvalidArgumentException(sprintf('Route preset "%s" accepts at most four arguments.', $method));
            }

            $this->groupWithPreset(
                self::NAMED_PRESETS[$method],
                $callback,
                $this->prefixArgument($arguments[1] ?? null),
                $this->domainArgument($arguments[2] ?? null),
                $this->namePrefixArgument($arguments[3] ?? null),
            );

            return null;
        }

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
        $this->kernel();

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
        $this->kernel();

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
        $this->kernel();

        return WebrickRouter::temporaryUrlUntil($name, $expiresAt, $params, $query, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function urlFor(string $name, array $params = [], array $query = [], bool $absolute = false): string
    {
        $this->kernel();

        return WebrickRouter::urlFor($name, $params, $query, $absolute);
    }

    /**
     * @return list<string>|string|Closure|null
     */
    private function domainArgument(mixed $value): array|string|Closure|null
    {
        if ($value === null || is_string($value) || $value instanceof Closure) {
            return $value;
        }

        return $this->stringListArgument($value, 'domain');
    }

    private function namePrefixArgument(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Route preset name prefix must be a string.');
    }

    /**
     * @return list<string>|string|null
     */
    private function prefixArgument(mixed $value): array|string|null
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return $this->stringListArgument($value, 'prefix');
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

    /**
     * @return list<string>
     */
    private function stringListArgument(mixed $value, string $argument): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Route preset %s must be a string or list of strings.', $argument));
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException(sprintf('Route preset %s must contain only strings.', $argument));
            }

            $items[] = $item;
        }

        return $items;
    }
}
