<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Definition\Registrar;

final readonly class RoutePresetRegistrar
{
    public function __construct(
        private RouteMiddlewareRegistrar $middleware,
        private ConfigRepository $config,
    ) {}

    public function register(): void
    {
        $this->middleware->register();
    }

    public function apiAuth(
        Registrar $router,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->group($router, 'api-auth', $callback, $prefix, $domain, $namePrefix);
    }

    public function authMfa(
        Registrar $router,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->group($router, 'mfa-auth', $callback, $prefix, $domain, $namePrefix);
    }

    public function authVerified(
        Registrar $router,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->group($router, 'verified-auth', $callback, $prefix, $domain, $namePrefix);
    }

    public function authWeb(
        Registrar $router,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        $this->group($router, 'web-auth', $callback, $prefix, $domain, $namePrefix);
    }

    public function group(
        Registrar $router,
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
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
     * @return list<string>
     */
    public function stack(string $preset): array
    {
        $stack = $this->configuredGroups()[$preset] ?? $this->builtInGroups()[$preset] ?? [];

        if (!is_array($stack)) {
            return [];
        }

        return $this->normalizeStack($stack);
    }

    /**
     * @return array<string, list<string>>
     */
    private function builtInGroups(): array
    {
        return [
            'api-auth' => ['resolve-auth', 'auth'],
            'auth:mfa' => ['resolve-auth', 'auth', 'mfa'],
            'auth:verified' => ['resolve-auth', 'auth', 'verified'],
            'auth:web' => ['resolve-auth', 'auth'],
            'mfa-auth' => ['resolve-auth', 'auth', 'mfa'],
            'verified-auth' => ['resolve-auth', 'auth', 'verified'],
            'web-auth' => ['resolve-auth', 'auth'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredGroups(): array
    {
        $configured = $this->config->get('router.middleware_groups', []);
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
     * @param list<mixed> $stack
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
}
