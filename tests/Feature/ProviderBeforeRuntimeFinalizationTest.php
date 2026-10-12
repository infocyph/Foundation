<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\WebGraphFactory;
use Infocyph\Foundation\Runtime\NonWebGraphFactory;

it('contributes all built-in services before materializing a web development container', function (): void {
    $graph = new WebGraphFactory()->compose([]);
    try {
        expect($graph->application->container()->get(Application::class))->toBe($graph->application);
    } finally {
        $graph->application->container()->unset();
    }
});

it('contributes all built-in services before materializing non-web development containers', function (RuntimeMode $mode): void {
    $graph = new NonWebGraphFactory()->compose([], $mode);
    try {
        expect($graph->application->container()->get(Application::class))->toBe($graph->application);
    } finally {
        $graph->application->container()->unset();
    }
})->with([
    'cli' => [RuntimeMode::Cli],
    'worker' => [RuntimeMode::Worker],
    'scheduler' => [RuntimeMode::Scheduler],
]);

it('retains direct application creation and normal runtime binding after provider contribution', function (): void {
    $application = Foundation::cli([]);
    try {
        expect($application->container()->get(Application::class))->toBe($application);
    } finally {
        $application->container()->unset();
    }
});
