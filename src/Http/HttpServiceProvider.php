<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Psr\Log\NullLogger;

final class HttpServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(ErrorHandler::class, fn() => new ErrorHandler(
            logger: new NullLogger(),
            debug: (bool) $app->config()->get('app.debug', false),
            capturePhpErrors: true,
            requestIdHeader: 'X-Request-Id',
            responseRenderer: function (Request $request, \Throwable $exception, int $status, array $headers) use ($app): ?Response {
                unset($status, $headers);

                $mapper = $app->make(AuthExceptionMapper::class);

                return $mapper->supports($exception)
                    ? $mapper->toResponse($request, $exception)
                    : null;
            },
        ), LifetimeEnum::Singleton);

        $container->bind(HttpKernel::class, fn() => new HttpKernel(
            router: $app->make(RouterManager::class),
            errorHandler: $app->make(ErrorHandler::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.http', fn() => $container->get(HttpKernel::class), LifetimeEnum::Singleton);
    }
}
