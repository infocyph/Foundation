<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Http\Response\ValidationExceptionMapper;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Foundation\Routing\WebrickRouterFactory;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
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
        $this->bindRecipe($container, ValidationExceptionMapper::class, ValidationExceptionMapper::class);
        $this->bindRecipe($container, ExceptionRenderer::class, ExceptionRenderer::class, [
            new ServiceReference(AuthExceptionMapper::class),
            new ServiceReference(ValidationExceptionMapper::class),
        ]);
        $this->bindFactory(
            $container,
            MaintenanceManager::class,
            fn() => new MaintenanceManager($app),
            LifetimeEnum::Singleton,
        );

        $this->bindFactory($container, ErrorHandler::class, fn() => new ErrorHandler(
            logger: static fn(): LoggerInterface => $app->make(HttpExceptionLogger::class),
            debug: (bool) $app->config()->get('app.debug', false),
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static fn(
                \Infocyph\Webrick\Request\Request $request,
                \Throwable $exception,
            ): ?\Infocyph\Webrick\Response\Response => ExceptionRenderer::supports($exception)
                ? $app->make(ExceptionRenderer::class)->render($request, $exception)
                : null,
        ), LifetimeEnum::Singleton);

        $this->bindFactory(
            $container,
            RouterKernel::class,
            fn() => $app->make(WebrickRouterFactory::class)->kernel($app->make(ErrorHandler::class)),
            LifetimeEnum::Singleton,
        );
        $this->bindRecipe($container, HttpKernel::class, HttpKernel::class, [
            new ServiceReference(RouterKernel::class),
            new ServiceReference(ExecutionScope::class),
            new ServiceReference(MaintenanceManager::class),
        ]);

        $this->bindFactory($container, 'foundation.http', fn() => $container->get(HttpKernel::class), LifetimeEnum::Singleton);
    }
}
