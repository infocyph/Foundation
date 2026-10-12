<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Runtime\NonWebProductionGraph;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

it('removes transient runtime identities without mutable definition introspection', function (): void {
    $builder = ContainerBuilder::create('foundation.test.production');
    $builder->value(Application::class, 'development-only');
    $builder->value(Container::class, 'development-only');

    new NonWebProductionGraph()->prepare($builder);

    expect($builder->definitions()->has(Application::class))->toBeFalse()
        ->and($builder->definitions()->has(Container::class))->toBeFalse();

    new NonWebProductionGraph()->prepare($builder);

    expect($builder->definitions()->has(Application::class))->toBeFalse()
        ->and($builder->definitions()->has(Container::class))->toBeFalse();
});
