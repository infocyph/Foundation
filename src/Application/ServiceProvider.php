<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\InterMix\DI\ContainerBuilder;

abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Application $app): void {}

    final protected function application(
        ContainerBuilder $builder,
        ?FoundationBuildContext $context = null,
    ): Application {
        $container = $builder->development();
        if (!$container->definitions()->has(Application::class)) {
            throw new \LogicException('Foundation Application must exist before provider contribution.');
        }

        $app = $container->get(Application::class);
        if (!$app instanceof Application) {
            throw new \LogicException('Foundation Application binding is invalid during provider contribution.');
        }

        return $app;
    }
}
