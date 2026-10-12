<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Foundation\Routing\WebrickRouterFactory;
use Infocyph\Webrick\Router\Definition\Registrar;

it('contributes native route and HTTP factories without a partially resolved Application', function (): void {
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([]), RuntimeMode::Web, []);
    $builder = FoundationGraph::compose($context);
    new RoutingServiceProvider()->contribute($builder, $context);
    new HttpServiceProvider()->contribute($builder, $context);

    $definitions = $builder->definitionGraph()->definitions();
    expect(array_key_exists(Application::class, $definitions))->toBeFalse()
        ->and(array_key_exists(Registrar::class, $definitions))->toBeTrue()
        ->and(array_key_exists(WebrickRouterFactory::class, $definitions))->toBeTrue()
        ->and(array_key_exists(\Infocyph\Foundation\Http\HttpKernel::class, $definitions))->toBeTrue();
});
