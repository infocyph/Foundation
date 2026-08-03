<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Definition\Registrar;

final readonly class RoutePresetRegistrar
{
    private const array BUILT_IN_GROUPS = [
        'api-auth' => ['resolve-auth', 'auth'],
        'mfa-auth' => ['resolve-auth', 'auth', 'mfa'],
        'verified-auth' => ['resolve-auth', 'auth', 'verified'],
        'web' => ['session', 'csrf'],
        'web-auth' => ['session', 'csrf', 'resolve-auth', 'auth'],
    ];

    private const array GROUP_ALIASES = [
        'auth:mfa' => 'mfa-auth',
        'auth:verified' => 'verified-auth',
        'auth:web' => 'web-auth',
    ];

    /** @var array<string, string> */
    private const array NAMED_PRESETS = [
        'apiAuth' => 'api-auth',
        'authMfa' => 'mfa-auth',
        'authVerified' => 'verified-auth',
        'authWeb' => 'web-auth',
    ];

    public function __construct(
        private RouteMiddlewareRegistrar $middleware,
        private ConfigRepository $config,
    ) {}

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public function group(
        Registrar $router,
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $router->group(
            prefix: $prefix,
            domain: $domain,
            middleware: $this->stack($preset),
            namePrefix: $namePrefix,
            callback: $callback,
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    public function invokeNamed(Registrar $router, string $method, array $arguments): bool
    {
        $preset = self::NAMED_PRESETS[$method] ?? null;
        if ($preset === null) {
            return false;
        }

        $callback = $arguments[0] ?? null;
        if (!$callback instanceof Closure) {
            throw new \InvalidArgumentException(sprintf('Route preset "%s" requires a closure callback.', $method));
        }
        if (count($arguments) > 4) {
            throw new \InvalidArgumentException(sprintf('Route preset "%s" accepts at most four arguments.', $method));
        }

        $this->group(
            $router,
            $preset,
            $callback,
            $this->prefixArgument($arguments[1] ?? null),
            $this->domainArgument($arguments[2] ?? null),
            $this->namePrefixArgument($arguments[3] ?? null),
        );

        return true;
    }

    public function register(): void
    {
        $this->middleware->register();
    }

    /**
     * @return list<string>
     */
    public function stack(string $preset): array
    {
        $stack = $this->configuredGroups()[$preset] ?? $this->builtInGroups()[$preset] ?? [];

        return $this->normalizeStack($stack);
    }

    /**
     * @return array<string, list<string>>
     */
    private function builtInGroups(): array
    {
        $groups = self::BUILT_IN_GROUPS;

        foreach (self::GROUP_ALIASES as $alias => $preset) {
            $groups[$alias] = $groups[$preset];
        }

        return $groups;
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredGroups(): array
    {
        $configured = $this->config->get('router.middleware.groups', []);
        if (!is_array($configured)) {
            return [];
        }

        $groups = [];
        foreach ($configured as $name => $stack) {
            if (!is_string($name) || !is_array($stack)) {
                continue;
            }

            $groups[$name] = $this->normalizeStack($stack);
        }

        return $groups;
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
     * @param array<mixed> $stack
     * @return list<string>
     */
    private function normalizeStack(array $stack): array
    {
        $normalized = [];
        foreach ($stack as $middleware) {
            if (!is_string($middleware) || $middleware === '') {
                continue;
            }

            $normalized[] = $middleware;
        }

        return $normalized;
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
     * @return list<string>
     */
    private function stringListArgument(mixed $value, string $argument): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Route preset %s must be a string or list of strings.',
                $argument,
            ));
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException(sprintf(
                    'Route preset %s must contain only strings.',
                    $argument,
                ));
            }

            $items[] = $item;
        }

        return $items;
    }
}
