<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Http\Response\ValidationExceptionMapper;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Foundation\Operations\MaintenanceRuntimeState;
use Infocyph\Foundation\Routing\WebrickRouterFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class HttpServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);
        $appConfig = is_array($context->config['app'] ?? null) ? $context->config['app'] : [];
        $operations = is_array($context->config['operations'] ?? null) ? $context->config['operations'] : [];
        $maintenance = is_array($operations['maintenance'] ?? null) ? $operations['maintenance'] : [];
        $refreshMilliseconds = $this->nonNegativeInt($maintenance['refresh_milliseconds'] ?? null, 1000);
        $retryAfter = $this->nonNegativeInt($maintenance['retry_after'] ?? null, 3600);

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

        $builder->singleton(MaintenanceManager::class, FactoryDefinition::construct(
            MaintenanceManager::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(PathManager::class),
                new ServiceReference(ContainerInterface::class),
            ],
        ));
        $builder->singleton(MaintenanceRuntimeState::class, FactoryDefinition::construct(
            MaintenanceRuntimeState::class,
            [
                new ServiceReference(MaintenanceManager::class),
                $refreshMilliseconds,
                $retryAfter,
            ],
        ));

        // Error-handler and live RouterKernel ownership remain development/embedded-only.
        // Production web execution uses the compiled Webrick release runtime.
        $builder->bindFactory(ErrorHandler::class, fn() => new ErrorHandler(
            logger: static fn(): LoggerInterface => $app->make(HttpExceptionLogger::class),
            debug: ($appConfig['debug'] ?? false) === true,
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
            [new ServiceReference(RouterKernel::class)],
        ));

        $builder->alias('foundation.http', HttpKernel::class);
    }

    private function nonNegativeInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }
}
