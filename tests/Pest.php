<?php

declare(strict_types=1);

/** Restore Webrick's process registries after an in-process production-runtime test. */
function foundationResetWebrickProductionRegistries(): void
{
    foreach ([
        \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::class,
        \Infocyph\Webrick\Router\Url\UrlGeneratorRegistry::class,
        \Infocyph\Webrick\Router\Constraint\Registry::class,
        \Infocyph\Webrick\Response\Headers\HeaderPolicy::class,
    ] as $registry) {
        $frozen = new ReflectionProperty($registry, 'frozen');
        $frozen->setValue(null, false);
    }

    \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::reset();
    \Infocyph\Webrick\Router\Facade\Router::reset();
}
