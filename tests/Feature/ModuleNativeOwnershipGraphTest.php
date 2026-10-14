<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Foundation\Routing\RoutingServiceProvider;

function foundationB4SelectedProviders(RuntimeMode $mode, array $features): array
{
    $config = new ConfigRepository([
        'app' => ['capabilities' => ['database', 'filesystem', 'messaging', 'auth', 'cache']],
        'modules' => ['features' => $features],
    ]);
    $context = FoundationBuildContext::fromConfig($config, $mode);
    $registry = new ServiceRegistry();
    new Bootstrapper()->compose(FoundationGraph::compose($context), $context, $registry);

    return $registry->classes();
}

it('keeps Webrick native HTTP and route owners for every selected module topology', function (): void {
    foreach ([[], ['data.database' => true], ['data.filesystem' => true]] as $features) {
        $providers = foundationB4SelectedProviders(RuntimeMode::Web, $features);
        expect($providers)->toContain(RoutingServiceProvider::class, HttpServiceProvider::class)
            ->not->toContain(MessagingServiceProvider::class);

        if ($features === []) {
            expect($providers)->not->toContain(DatabaseServiceProvider::class, FilesystemServiceProvider::class);
        }
    }
});

it('never contributes web-owned routing providers to CLI, worker or scheduler graphs', function (): void {
    foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $mode) {
        $providers = foundationB4SelectedProviders($mode, ['data.database' => true]);
        expect($providers)->toContain(DatabaseServiceProvider::class)
            ->not->toContain(RoutingServiceProvider::class, HttpServiceProvider::class)
            ->not->toContain(FilesystemServiceProvider::class, MessagingServiceProvider::class);
    }
});

it('does not infer Runwire ownership or activate unrelated execution features', function (): void {
    foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $mode) {
        $context = FoundationBuildContext::fromConfig(new ConfigRepository([
            'app' => ['capabilities' => ['runwire', 'messaging', 'communication']],
            'modules' => ['features' => ['execution.runtime' => true]],
        ]), $mode);

        expect($context->hasFeature('execution.runtime'))->toBeTrue()
            ->and($context->hasFeature('execution.messaging'))->toBeFalse()
            ->and($context->hasFeature('execution.communication'))->toBeFalse()
            ->and($context->permitsCapability('messaging'))->toBeFalse()
            ->and($context->permitsCapability('communication'))->toBeFalse();
    }
});

it('normalizes equivalent feature maps to identical selected native provider ordering', function (): void {
    foreach ([RuntimeMode::Web, RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $mode) {
        $first = foundationB4SelectedProviders($mode, [
            'data.database' => true,
            'data.filesystem' => false,
            'execution.messaging' => false,
        ]);
        $reordered = foundationB4SelectedProviders($mode, [
            'execution.messaging' => false,
            'data.filesystem' => false,
            'data.database' => true,
        ]);

        expect($first)->toBe($reordered);
    }
});
