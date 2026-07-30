<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Runtime\RuntimeContextResetter;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final class HttpServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindFactory($container, AuthResponseFactory::class, fn() => new AuthResponseFactory(), LifetimeEnum::Singleton);
        $this->bindFactory($container, AuthExceptionMapper::class, fn() => new AuthExceptionMapper(
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, ExceptionRenderer::class, fn() => new ExceptionRenderer(
            $app->make(AuthExceptionMapper::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, ErrorHandler::class, fn() => new ErrorHandler(
            logger: $app->make(HttpExceptionLogger::class),
            debug: (bool) $app->config()->get('app.debug', false),
            capturePhpErrors: true,
            requestIdHeader: 'X-Request-Id',
            responseRenderer: $app->make(ExceptionRenderer::class)->render(...),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, HttpKernel::class, fn() => new HttpKernel(
            router: $app->make(RouterManager::class),
            errorHandler: $app->make(ErrorHandler::class),
            contexts: $app->make(RuntimeContextResetter::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.http', fn() => $container->get(HttpKernel::class), LifetimeEnum::Singleton);
    }
}
