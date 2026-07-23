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
    /** @var array<string, string> */
    private const array NAMED_PRESETS = [
        'apiAuth' => 'api-auth',
        'authMfa' => 'mfa-auth',
        'authVerified' => 'verified-auth',
        'authWeb' => 'web-auth',
    ];

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
        if (isset(self::NAMED_PRESETS[$method])) {
            $callback = $arguments[0] ?? null;
            if (!$callback instanceof Closure) {
                throw new \InvalidArgumentException(sprintf('Route preset "%s" requires a closure callback.', $method));
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
