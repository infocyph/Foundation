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
            logger: static fn(): LoggerInterface => self::logger($container),
            debug: $debug,
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static fn(Request $request, Throwable $exception): ?Response =>
                ExceptionRenderer::supports($exception)
                    ? self::renderer($container)->render($request, $exception)
                    : null,
        );
    }

    private static function logger(ContainerInterface $container): LoggerInterface
    {
        $logger = $container->get(HttpExceptionLogger::class);
        if (!$logger instanceof LoggerInterface) {
            throw new \UnexpectedValueException('Webrick HTTP exception logger must implement LoggerInterface.');
        }

        return $logger;
    }

    private static function renderer(ContainerInterface $container): ExceptionRenderer
    {
        $renderer = $container->get(ExceptionRenderer::class);
        if (!$renderer instanceof ExceptionRenderer) {
            throw new \UnexpectedValueException('Webrick HTTP exception renderer has an invalid binding.');
        }

        return $renderer;
    }

    public static function routerKernel(WebrickRouterFactory $factory, ErrorHandler $errorHandler): RouterKernel
    {
        return $factory->kernel($errorHandler);
    }
}
