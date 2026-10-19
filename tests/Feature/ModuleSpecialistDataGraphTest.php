<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;
use League\Flysystem\FilesystemOperator;

it('binds DBLayer and Pathwise only for individually selected data features', function (): void {
    foreach ([
        ['features' => [], 'database' => false, 'filesystem' => false],
        ['features' => ['data.database' => true], 'database' => true, 'filesystem' => false],
        ['features' => ['data.filesystem' => true], 'database' => false, 'filesystem' => true],
        ['features' => ['data.database' => true, 'data.filesystem' => true], 'database' => true, 'filesystem' => true],
    ] as $case) {
        $context = FoundationBuildContext::fromConfig(new ConfigRepository([
            'app' => ['capabilities' => ['cache', 'database', 'filesystem']],
            'modules' => ['features' => $case['features']],
        ]), RuntimeMode::Cli);
        $builder = FoundationGraph::compose($context);
        $registry = new ServiceRegistry();
        new Bootstrapper()->compose($builder, $context, $registry);

        expect(in_array(DatabaseServiceProvider::class, $registry->classes(), true))->toBe($case['database'])
            ->and(in_array(FilesystemServiceProvider::class, $registry->classes(), true))->toBe($case['filesystem'])
            ->and(in_array(CacheServiceProvider::class, $registry->classes(), true))->toBeTrue()
            ->and($builder->definitionGraph()->hasDefinition(Connection::class))->toBe($case['database'])
            ->and($builder->definitionGraph()->hasDefinition(FilesystemOperator::class))->toBe($case['filesystem']);
    }
});

it('keeps data feature config separate and Core schema ownership out of optional repair', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = [
        'root_known' => true,
        'root_requirements' => [],
        'installed' => [],
        'enabled' => [],
        'selected_features' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo'],
        'published_configs' => [],
    ];
    $database = $planner->plan('install', ['data.database'], $context);
    $filesystem = $planner->plan('install', ['data.filesystem'], $context);

    expect($database['effects']['publish'])->toBe(['database.php'])
        ->and($filesystem['effects']['publish'])->toBe(['filesystem.php'])
        ->and($database['effects']['schemas'])->toBe([])
        ->and($filesystem['effects']['schemas'])->toBe([])
        ->and($database['effects']['require'])->toBe(['infocyph/dblayer' => '^6.1.2'])
        ->and($filesystem['effects']['require'])->toBe(['infocyph/pathwise' => '^4.2']);
});
