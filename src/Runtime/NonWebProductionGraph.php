<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

/** Removes development-only runtime identity before generated compilation/load. */
final class NonWebProductionGraph
{
    private const array DEVELOPMENT_ONLY = [
        Application::class,
        Container::class,
        ContainerCacheManager::class,
    ];

    public function prepare(ContainerBuilder $builder): void
    {
        foreach (self::DEVELOPMENT_ONLY as $id) {
            if ($builder->definitions()->has($id)) {
                $builder->unbind($id);
            }
        }
    }
}
