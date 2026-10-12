<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Command\System\ModuleSystemCommand;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\WebGraphFactory;
use Infocyph\Foundation\Runtime\NonWebGraphFactory;

it('contributes all built-in services before materializing a web development container', function (): void {
    $graph = new WebGraphFactory()->compose([]);
    expect($graph->application->container()->get(Application::class))->toBe($graph->application);
});

it('contributes all built-in services before materializing non-web development containers', function (RuntimeMode $mode): void {
    $graph = new NonWebGraphFactory()->compose([], $mode);
    expect($graph->application->container()->get(Application::class))->toBe($graph->application);
})->with([
    'cli' => [RuntimeMode::Cli],
    'worker' => [RuntimeMode::Worker],
    'scheduler' => [RuntimeMode::Scheduler],
]);

it('retains direct application creation and normal runtime binding after provider contribution', function (): void {
    $application = Foundation::cli([]);
    expect($application->container()->get(Application::class))->toBe($application);
});

it('resolves explicit service bindings and independently makes a native command handler', function (): void {
    $application = Foundation::cli([]);

    expect($application->make(Application::class))->toBe($application)
        ->and($application->make(ModuleSystemCommand::class))->toBeInstanceOf(ModuleSystemCommand::class);
});
