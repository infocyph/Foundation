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
        private readonly WebrickMiddlewareFactory $middleware,
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
            preGlobal: $this->middleware->preGlobal(),
            postGlobal: $this->middleware->postGlobal(),
            errorHandler: $errorHandler,
            bindUrlServices: $this->bindUrlServicesCallback(),
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
        $registrar = new Registrar(
            routes: $this->routes,
            autoSlashRedirect: (bool) $this->config->get('router.auto_slash_redirect', false),
            exposeUrlServices: (bool) $this->config->get('router.expose_url_services', false),
            signKey: $this->optionalString($this->config->get('router.signed_urls.key')),
            signedDefaultTtl: $this->optionalInt($this->config->get('router.signed_urls.default_ttl')),
            signedUrlConfig: $this->signedUrlConfig(),
            urlBaseUri: $this->stringConfig('router.url_base_uri'),
        );
        $this->registrar = $registrar;

        Router::setInstance($registrar);
        $this->bindUrlServices();

        return $registrar;
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

    private function bindUrlServices(): void
    {
        $callback = $this->bindUrlServicesCallback();
        if ($callback === null) {
            return;
        }

        $callback($this->routes());
    }

    private function bindUrlServicesCallback(): ?\Closure
    {
        $baseUri = $this->stringConfig('router.url_base_uri');
        $signKey = $this->optionalString($this->config->get('router.signed_urls.key'));
        $signedConfig = $this->signedUrlConfig();
        $defaultTtl = $this->optionalInt($this->config->get('router.signed_urls.default_ttl'));
        $shouldBind = (bool) $this->config->get('router.expose_url_services', false)
            || $baseUri !== ''
            || $signKey !== null
            || $signedConfig !== null;

        if (!$shouldBind) {
            return null;
        }

        return static function (Collection $routes) use ($signKey, $defaultTtl, $signedConfig, $baseUri): void {
            Router::bindUrlServices(
                routes: $routes,
                signKey: $signKey,
                defaultTtl: $defaultTtl,
                signedUrlConfig: $signedConfig,
                baseUri: $baseUri,
            );
        };
    }

    private function matcher(): MatcherInterface
    {
        return match ($this->stringConfig('router.matcher', 'fused')) {
            'generated' => GeneratedMatcher::make(),
            'sharded' => ShardedMatcher::make(),
            default => FusedMatcher::make(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSignedUrlOptions(mixed $signedOptions): array
    {
        if (!is_array($signedOptions)) {
            return [];
        }

        $normalized = [];

        foreach ($signedOptions as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[match ($key) {
                'default_ttl' => 'defaultTtl',
                'expiry_param' => 'expiryParam',
                'generation_key' => 'generationKey',
                'ignored_query_params' => 'ignoredQueryParams',
                'payload_mode' => 'payloadMode',
                'signature_param' => 'signatureParam',
                'verification_keys' => 'verificationKeys',
                default => $key,
            }] = $value;
        }

        return $normalized;
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
        $signedOptions = $this->normalizeSignedUrlOptions(
            $this->config->get('router.signed_urls.options'),
        );

        return $signedOptions !== []
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
