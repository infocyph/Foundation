<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Http\Response\ValidationExceptionMapper;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Foundation\Routing\WebrickRouterFactory;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Log\LoggerInterface;

final class HttpServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);

        $builder->singleton(AuthResponseFactory::class, FactoryDefinition::construct(AuthResponseFactory::class));
        $builder->singleton(AuthExceptionMapper::class, FactoryDefinition::construct(
            AuthExceptionMapper::class,
            [new ServiceReference(AuthResponseFactory::class)],
        ));
        $builder->singleton(ValidationExceptionMapper::class, FactoryDefinition::construct(
            ValidationExceptionMapper::class,
        ));
        $builder->singleton(ExceptionRenderer::class, FactoryDefinition::construct(
            ExceptionRenderer::class,
            [
                new ServiceReference(AuthExceptionMapper::class),
                new ServiceReference(ValidationExceptionMapper::class),
            ],
        ));

        // Maintenance is a Phase-6 handoff boundary until it leaves the universal HTTP kernel.
        $builder->bindFactory(MaintenanceManager::class, fn() => new MaintenanceManager($app));

        // Error-handler and live RouterKernel ownership move to the compiled Webrick runtime in Phase 5.
        $builder->bindFactory(ErrorHandler::class, fn() => new ErrorHandler(
            logger: static fn(): LoggerInterface => $app->make(HttpExceptionLogger::class),
            debug: (bool) ($context->config['app']['debug'] ?? false),
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static fn(
                \Infocyph\Webrick\Request\Request $request,
                \Throwable $exception,
            ): ?\Infocyph\Webrick\Response\Response => ExceptionRenderer::supports($exception)
                ? $app->make(ExceptionRenderer::class)->render($request, $exception)
                : null,
        ));
        $builder->bindFactory(
            RouterKernel::class,
            fn() => $app->make(WebrickRouterFactory::class)->kernel($app->make(ErrorHandler::class)),
        );
        $builder->singleton(HttpKernel::class, FactoryDefinition::construct(
            HttpKernel::class,
            [
                new ServiceReference(RouterKernel::class),
                new ServiceReference(ExecutionScope::class),
                new ServiceReference(MaintenanceManager::class),
            ],
        ));

        $builder->alias('foundation.http', HttpKernel::class);
    }
}
