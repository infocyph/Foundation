<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DatabaseMigrationManager;
use Infocyph\Foundation\Foundation;

it('binds configured migration locks from the complete capability topology, not partial definitions', function (): void {
    $config = [
        'app' => ['capabilities' => ['database', 'cache']],
        'database' => [
            'default' => 'testing',
            'connections' => ['testing' => ['driver' => 'sqlite', 'database' => ':memory:']],
            'migrations' => ['lock_store' => 'memory', 'classes' => []],
        ],
        'cache' => [
            'default' => 'memory',
            'stores' => ['memory' => ['driver' => 'memory']],
            'lock' => ['driver' => 'file', 'path' => sys_get_temp_dir()],
        ],
    ];

    $application = Foundation::cli($config);
    try {
        expect($application->providers()->classes())->toContain(CacheServiceProvider::class);
        $application->execution()->run(static function () use ($application): void {
            expect($application->make(DatabaseMigrationManager::class)->runner())
                ->toBeInstanceOf(MigrationRunner::class);
        });
    } finally {
        DB::purge();
    }

    $config['app']['capabilities'] = ['database'];
    $databaseOnly = Foundation::cli($config);

    try {
        expect($databaseOnly->providers()->classes())->not->toContain(CacheServiceProvider::class);
        $databaseOnly->execution()->run(static function () use ($databaseOnly): void {
            expect(fn() => $databaseOnly->make(DatabaseMigrationManager::class)->runner())
                ->toThrow(LogicException::class, 'Database migration locks require the Foundation cache capability.');
        });
    } finally {
        DB::purge();
    }
});

it('applies the same native provider-selection policy to explicit and discovered capabilities', function (): void {
    $implicit = FoundationBuildContext::fromConfig(new ConfigRepository([]), RuntimeMode::Cli);
    $explicit = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['capabilities' => ['cache']]]),
        RuntimeMode::Cli,
    );
    $overridden = FoundationBuildContext::fromConfig(
        new ConfigRepository(['modules' => ['capabilities' => ['database' => false]]]),
        RuntimeMode::Cli,
    );

    expect($implicit->permitsCapability('database'))->toBeTrue()
        ->and($explicit->permitsCapability('cache'))->toBeTrue()
        ->and($explicit->permitsCapability('database'))->toBeFalse()
        ->and($overridden->permitsCapability('database'))->toBeFalse()
        ->and($overridden->permitsCapability('cache'))->toBeTrue();
});
