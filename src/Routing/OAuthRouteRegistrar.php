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

    public function register(Registrar $router): void
    {
        if ($this->config->get('auth.oauth.enabled', false) !== true) {
            return;
        }

        $authorizationMiddleware = [
            'oauth-throttle:authorization',
            ...$this->presets->stack('web-auth'),
        ];

        $router->get(
            '/.well-known/oauth-authorization-server',
            [OAuthHttpHandler::class, 'metadata'],
            'oauth.metadata',
        );
        $router->get(
            $this->path('jwks'),
            [OAuthHttpHandler::class, 'jwks'],
            'oauth.jwks',
        );
        $router->get(
            $this->path('authorization'),
            [OAuthAuthorizationController::class, 'authorization'],
            ['as' => 'oauth.authorization', 'middleware' => $authorizationMiddleware],
        );
        $router->post(
            $this->path('authorization'),
            [OAuthAuthorizationController::class, 'authorization'],
            ['as' => 'oauth.authorization.decision', 'middleware' => $authorizationMiddleware],
        );

        foreach (['token', 'revocation', 'introspection'] as $endpoint) {
            $router->post(
                $this->path($endpoint),
                [OAuthHttpHandler::class, $endpoint],
                [
                    'as' => 'oauth.' . $endpoint,
                    'middleware' => ['oauth-throttle:' . $endpoint],
                ],
            );
        }
    }

    private function path(string $endpoint): string
    {
        $key = 'auth.oauth.routes.' . $endpoint;
        $path = $this->config->get($key);
        if (!is_string($path) || $path === '') {
            throw new ConfigurationException(sprintf('%s must be a non-empty route path.', $key));
        }

        return $path;
    }
}
