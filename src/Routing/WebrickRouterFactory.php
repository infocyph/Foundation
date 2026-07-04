<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

final class WebrickRouterFactory
{
    private ?Registrar $registrar = null;

    private ?Collection $routes = null;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function kernel(?ErrorHandler $errorHandler = null): RouterKernel
    {
        $routes = $this->routes();
        $aliases = $this->aliasesByRoute($routes);

        return RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: $this->matcher(),
            register: function (Registrar $registrar) use ($routes, $aliases): void {
                foreach ($routes->all() as $route) {
                    $this->replayRoute(
                        $registrar,
                        $route,
                        $aliases[spl_object_id($route)] ?? [],
                    );
                }
            },
            routeCache: $this->optionalString($this->config->get('router.cache')),
            registrarOptions: [
                'autoSlashRedirect' => (bool) $this->config->get('router.auto_slash_redirect', false),
                'exposeUrlServices' => (bool) $this->config->get('router.expose_url_services', false),
                'signKey' => $this->optionalString($this->config->get('router.signed_urls.key')),
                'signedDefaultTtl' => $this->optionalInt($this->config->get('router.signed_urls.default_ttl')),
                'signedUrlConfig' => $this->signedUrlConfig(),
                'urlBaseUri' => $this->stringConfig('router.url_base_uri'),
            ],
            preGlobal: [],
            postGlobal: [],
            errorHandler: $errorHandler,
            fallbackAliasesFromRegistrar: true,
        );
    }

    public function router(): Registrar
    {
        if ($this->registrar !== null) {
            Router::setInstance($this->registrar);

            return $this->registrar;
        }

        $this->routes ??= new Collection();
        $this->registrar = new Registrar(
            routes: $this->routes,
            autoSlashRedirect: (bool) $this->config->get('router.auto_slash_redirect', false),
            exposeUrlServices: (bool) $this->config->get('router.expose_url_services', false),
            signKey: $this->optionalString($this->config->get('router.signed_urls.key')),
            signedDefaultTtl: $this->optionalInt($this->config->get('router.signed_urls.default_ttl')),
            signedUrlConfig: $this->signedUrlConfig(),
            urlBaseUri: $this->stringConfig('router.url_base_uri'),
        );

        Router::setInstance($this->registrar);

        return $this->registrar;
    }

    public function routes(): Collection
    {
        $this->router();

        return $this->routes ??= new Collection();
    }

    /**
     * @return array<int, list<string>>
     */
    private function aliasesByRoute(Collection $routes): array
    {
        $aliases = [];

        foreach ($routes->aliasIndex() as $name => $tuple) {
            unset($tuple);

            $route = $routes->findByName($name);
            if (!$route instanceof RouteInterface) {
                continue;
            }

            $primaryName = $route->getName();
            if ($primaryName === null || $primaryName === '' || $primaryName === $name) {
                continue;
            }

            $aliases[spl_object_id($route)][] = $name;
        }

        return $aliases;
    }

    private function matcher(): MatcherInterface
    {
        return match ($this->stringConfig('router.matcher', 'fused')) {
            'generated' => GeneratedMatcher::make(),
            'sharded' => ShardedMatcher::make(),
            default => FusedMatcher::make(),
        };
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? (int) $value
            : null;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @param list<string> $aliases
     */
    private function replayRoute(Registrar $registrar, RouteInterface $route, array $aliases): void
    {
        $register = function (Registrar $target) use ($route, $aliases): void {
            $method = strtolower($route->getMethod());
            $options = [];

            if ($route->getMiddlewares() !== []) {
                $options['middleware'] = $route->getMiddlewares();
            }

            if ($route->getName() !== null && $route->getName() !== '') {
                $options['name'] = $route->getName();
            }

            if ($aliases !== []) {
                $options['aliases'] = $aliases;
            }

            $target->{$method}(
                $route->getPath(),
                $route->getHandler(),
                $options !== [] ? $options : null,
            );
        };

        $domain = $route->getDomain();
        if ($domain !== null && $domain !== '') {
            $registrar->group(
                domain: $domain,
                callback: static fn() => $register($registrar),
            );

            return;
        }

        $register($registrar);
    }

    private function signedUrlConfig(): ?SignedUrlConfig
    {
        $signedOptions = $this->config->get('router.signed_urls.options');

        return is_array($signedOptions) && $signedOptions !== []
            ? SignedUrlConfig::fromArray($signedOptions)
            : null;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value)
            ? $value
            : $default;
    }
}
