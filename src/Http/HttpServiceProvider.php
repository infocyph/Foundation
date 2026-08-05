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
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Psr\Log\LoggerInterface;

final class HttpServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindRecipe($container, AuthResponseFactory::class, AuthResponseFactory::class);
        $this->bindRecipe($container, AuthExceptionMapper::class, AuthExceptionMapper::class, [
            new ServiceReference(AuthResponseFactory::class),
        ]);
        $this->bindRecipe($container, ExceptionRenderer::class, ExceptionRenderer::class, [
            new ServiceReference(AuthExceptionMapper::class),
        ]);

        $this->bindFactory($container, ErrorHandler::class, fn() => new ErrorHandler(
            logger: static fn(): LoggerInterface => $app->make(HttpExceptionLogger::class),
            debug: (bool) $app->config()->get('app.debug', false),
            capturePhpErrors: true,
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static fn(
                \Infocyph\Webrick\Request\Request $request,
                \Throwable $exception,
            ): ?\Infocyph\Webrick\Response\Response => ExceptionRenderer::supports($exception)
                ? $app->make(ExceptionRenderer::class)->render($request, $exception)
                : null,
        ), LifetimeEnum::Singleton);

        $this->bindRecipe($container, HttpKernel::class, HttpKernel::class, [
            new ServiceReference(RouterManager::class),
            new ServiceReference(ErrorHandler::class),
            new ServiceReference(RuntimeContextResetter::class),
        ]);

        $this->bindFactory($container, 'foundation.http', fn() => $container->get(HttpKernel::class), LifetimeEnum::Singleton);
    }
}
