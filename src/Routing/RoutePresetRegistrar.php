<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Definition\Registrar;

/**
 * Foundation-owned named middleware stacks for application route groups.
 *
 * Route registration itself remains a native Webrick Registrar concern.
 */
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

    /** @param list<string>|null $requirements */
    public function register(?array $requirements = null): void
    {
        $this->middleware->register($requirements);
    }

    /** @return list<string> */
    public function stack(string $preset): array
    {
        $groups = $this->configuredGroups() + $this->builtInGroups();
        if (!array_key_exists($preset, $groups)) {
            throw new \InvalidArgumentException(sprintf('Unknown route middleware preset "%s".', $preset));
        }

        return $groups[$preset];
    }

    /** @return array<string, list<string>> */
    private function builtInGroups(): array
    {
        $groups = self::BUILT_IN_GROUPS;

        foreach (self::GROUP_ALIASES as $alias => $preset) {
            $groups[$alias] = $groups[$preset];
        }

        return $groups;
    }

    /** @return array<string, list<string>> */
    private function configuredGroups(): array
    {
        $configured = $this->config->get('router.middleware.groups', []);
        if (!is_array($configured)) {
            throw new \InvalidArgumentException('router.middleware.groups must be a middleware group map.');
        }

        $groups = [];
        foreach ($configured as $name => $stack) {
            if (!is_string($name) || $name === '' || !is_array($stack)) {
                throw new \InvalidArgumentException('Route middleware group names and stacks must be valid.');
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
        if (!array_is_list($stack)) {
            throw new \InvalidArgumentException('Route middleware stacks must be lists of middleware names.');
        }

        $normalized = [];
        foreach ($stack as $middleware) {
            if (!is_string($middleware) || trim($middleware) === '') {
                throw new \InvalidArgumentException('Route middleware stacks may contain only non-empty names.');
            }

            $normalized[] = $middleware;
        }

        return $normalized;
    }
}
