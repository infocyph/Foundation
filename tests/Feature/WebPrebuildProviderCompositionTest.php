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
    expect($builder->definitions()->has(Application::class))->toBeFalse();

    new RoutingServiceProvider()->contribute($builder, $context);
    new HttpServiceProvider()->contribute($builder, $context);

    expect($builder->definitions()->has(Registrar::class))->toBeTrue()
        ->and($builder->definitions()->has(WebrickRouterFactory::class))->toBeTrue()
        ->and($builder->definitions()->has(\Infocyph\Foundation\Http\HttpKernel::class))->toBeTrue();
});
