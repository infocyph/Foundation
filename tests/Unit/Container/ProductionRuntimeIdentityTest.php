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

    $production = new NonWebProductionGraph();
    $production->prepare($builder);
    $production->prepare($builder);

    $definitions = $builder->definitionGraph();
    expect($definitions->hasDefinition(Application::class))->toBeFalse()
        ->and($definitions->hasDefinition(Container::class))->toBeFalse();
});
