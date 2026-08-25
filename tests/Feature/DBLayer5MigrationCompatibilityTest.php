<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final class FoundationDBLayer5FirstMigration implements Migration
{
    public function id(): string
    {
        return '20260825000100_create_foundation_dblayer5_first';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->create('foundation_dblayer5_first', static function (Blueprint $table): void {
            $table->increments();
            $table->string('value');
        });
    }

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->dropIfExists('foundation_dblayer5_first');
    }
}

final class FoundationDBLayer5SecondMigration implements Migration
{
    public function id(): string
    {
        return '20260825000200_create_foundation_dblayer5_second';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->create('foundation_dblayer5_second', static function (Blueprint $table): void {
            $table->increments();
            $table->string('value');
        });
    }

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->dropIfExists('foundation_dblayer5_second');
    }
}

it('preserves DBLayer 5 pretend, step batches, exact rollback, refresh and reset semantics', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $schema = new SchemaManager($connection);
    $first = new FoundationDBLayer5FirstMigration();
    $second = new FoundationDBLayer5SecondMigration();
    $runner = new MigrationRunner($connection, [$first, $second]);

    try {
        $preview = $runner->pretend();

        expect($preview)->toHaveKeys([$first->id(), $second->id()])
            ->and($schema->hasTable('foundation_dblayer5_first'))->toBeFalse()
            ->and($schema->hasTable('foundation_dblayer5_second'))->toBeFalse()
            ->and($runner->run(step: true))->toBe([$first->id(), $second->id()])
            ->and($runner->status())->toBe([
                ['id' => $first->id(), 'applied' => true, 'batch' => 1],
                ['id' => $second->id(), 'applied' => true, 'batch' => 2],
            ])
            ->and($runner->rollbackBatch(1))->toBe([$first->id()])
            ->and($schema->hasTable('foundation_dblayer5_first'))->toBeFalse()
            ->and($schema->hasTable('foundation_dblayer5_second'))->toBeTrue()
            ->and($runner->status())->toBe([
                ['id' => $first->id(), 'applied' => false, 'batch' => null],
                ['id' => $second->id(), 'applied' => true, 'batch' => 2],
            ])
            ->and($runner->rollback(1))->toBe([$second->id()])
            ->and($runner->fresh(true))->toBe([$first->id(), $second->id()])
            ->and($schema->hasTable('foundation_dblayer5_first'))->toBeTrue()
            ->and($schema->hasTable('foundation_dblayer5_second'))->toBeTrue()
            ->and($runner->refresh(true))->toBe([$first->id(), $second->id()])
            ->and($runner->reset(true))->toBe([$second->id(), $first->id()])
            ->and($schema->hasTable('foundation_dblayer5_first'))->toBeFalse()
            ->and($schema->hasTable('foundation_dblayer5_second'))->toBeFalse();
    } finally {
        $connection->disconnect();
    }
});

it('keeps DBLayer 5 schema wipe and monitor surfaces compatible with Foundation commands', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $schema = new SchemaManager($connection);

    try {
        $schema->create('foundation_dblayer5_wipe', static function (Blueprint $table): void {
            $table->increments();
        });

        $monitor = new DatabaseMonitor($connection);
        expect($monitor->status())->toMatchArray([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ])
            ->and($monitor->snapshot())->toHaveKeys([
                'driver',
                'database',
                'status',
                'sessions',
                'long_running_queries',
                'locks',
                'table_metrics',
                'index_metrics',
                'replication',
                'errors',
            ])
            ->and($schema->hasTable('foundation_dblayer5_wipe'))->toBeTrue();

        $schema->dropAllTables(true);

        expect($schema->hasTable('foundation_dblayer5_wipe'))->toBeFalse();
    } finally {
        $connection->disconnect();
    }
});
