<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Routing\WebrickRouterFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Development-only Webrick kernel constructed from finalized runtime services. */
final class HttpRuntimeFactory
{
    public static function errorHandler(ContainerInterface $container, bool $debug): ErrorHandler
    {
        return new ErrorHandler(
            logger: static fn(): LoggerInterface => $container->get(HttpExceptionLogger::class),
            debug: $debug,
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static fn(Request $request, Throwable $exception): ?Response =>
                ExceptionRenderer::supports($exception)
                    ? $container->get(ExceptionRenderer::class)->render($request, $exception)
                    : null,
        );
    }

    public static function routerKernel(WebrickRouterFactory $factory, ErrorHandler $errorHandler): RouterKernel
    {
        return $factory->kernel($errorHandler);
    }
}
