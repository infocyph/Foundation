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
        'web-auth' => ['resolve-auth', 'auth'],
    ];

    private const array GROUP_ALIASES = [
        'auth:mfa' => 'mfa-auth',
        'auth:verified' => 'verified-auth',
        'auth:web' => 'web-auth',
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
}
