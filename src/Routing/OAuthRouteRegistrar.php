<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Webrick\Router\Definition\Registrar;

final readonly class OAuthRouteRegistrar
{
    public function __construct(
        private ConfigRepository $config,
        private RoutePresetRegistrar $presets,
    ) {}

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \InvalidArgumentException
     */
    public function register(Registrar $router): void
    {
        if ($this->config->get('auth.oauth.enabled', false) !== true) {
            return;
        }

        $authorizationMiddleware = [
            'oauth-throttle:authorization',
            ...$this->presets->stack('web-auth'),
        ];

        self::registerVerb($router, 'get', 
            '/.well-known/oauth-authorization-server',
            [OAuthHttpHandler::class, 'metadata'],
            'oauth.metadata',
        );
        if ($this->config->get('auth.oauth.oidc.enabled', false) === true) {
            self::registerVerb($router, 'get', 
                '/.well-known/openid-configuration',
                [OAuthHttpHandler::class, 'openIdMetadata'],
                'oidc.discovery',
            );
            self::registerVerb($router, 'get', 
                $this->openIdPath('userinfo_route'),
                [OAuthHttpHandler::class, 'userInfo'],
                ['as' => 'oidc.userinfo', 'middleware' => ['oauth-throttle:userinfo']],
            );
        }

        self::registerVerb($router, 'get', 
            $this->path('jwks'),
            [OAuthHttpHandler::class, 'jwks'],
            'oauth.jwks',
        );
        self::registerVerb($router, 'get', 
            $this->path('authorization'),
            [OAuthAuthorizationController::class, 'authorization'],
            ['as' => 'oauth.authorization', 'middleware' => $authorizationMiddleware],
        );
        self::registerVerb($router, 'post', 
            $this->path('authorization'),
            [OAuthAuthorizationController::class, 'authorization'],
            ['as' => 'oauth.authorization.decision', 'middleware' => $authorizationMiddleware],
        );

        foreach (['token', 'revocation', 'introspection'] as $endpoint) {
            self::registerVerb($router, 'post', 
                $this->path($endpoint),
                [OAuthHttpHandler::class, $endpoint],
                [
                    'as' => 'oauth.' . $endpoint,
                    'middleware' => ['oauth-throttle:' . $endpoint],
                ],
            );
        }
    }

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     */
    private function openIdPath(string $name): string
    {
        $key = 'auth.oauth.oidc.' . $name;
        $path = $this->config->get($key);
        if (!is_string($path) || $path === '') {
            throw new ConfigurationException(sprintf('%s must be a non-empty route path.', $key));
        }

        return $path;
    }

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     */
    private function path(string $endpoint): string
    {
        $key = 'auth.oauth.routes.' . $endpoint;
        $path = $this->config->get($key);
        if (!is_string($path) || $path === '') {
            throw new ConfigurationException(sprintf('%s must be a non-empty route path.', $key));
        }

        return $path;
    }

    /**
     * Register a native Webrick class-method route through its concrete verb
     * dispatcher. Registrar accepts these handlers via __call(), whereas its
     * PHPStan-only @method alias does not describe them to Psalm.
     *
     * @param 'get'|'post' $verb
     * @param array{0:class-string,1:non-empty-string} $handler
     * @param string|array{as:string,middleware:list<string>} $options
     * @throws \BadMethodCallException
     */
    private static function registerVerb(
        Registrar $router,
        string $verb,
        string $path,
        array $handler,
        string|array $options,
    ): void {
        $router->__call($verb, [$path, $handler, $options]);
    }
}
