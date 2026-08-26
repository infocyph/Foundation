<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('rolls back only the additive OAuth revision and preserves the released auth schema', function (): void {
    DB::purge();
    $factory = oauth21RollbackFactory('oauth-rollback');
    $connection = $factory->connection();
    $tables = new AuthTables();
    $base = new AuthSchema($tables);
    $mfa = new AuthMfaRevisionSchema($tables);
    $oauth = new AuthOAuthRevisionSchema($tables);
    $released = new MigrationRunner($connection, [$base, $mfa]);
    $runner = new MigrationRunner($connection, [$base, $mfa, $oauth]);
    $schema = new SchemaManager($connection);

    try {
        $released->run();
        $connection->table($tables->accounts())->insert([
            'id' => 'account-preserved',
            'identifier' => 'rollback@example.test',
            'status' => 'active',
            'password_hash' => null,
            'metadata' => null,
        ]);

        expect($runner->run())->toBe([$oauth->id()])
            ->and($runner->rollback(1))->toBe([$oauth->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeFalse();
        }
        foreach ($tables->all() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }
        expect($connection->select('SELECT id FROM ' . $tables->accounts() . ' WHERE id = ?', ['account-preserved']))->toHaveCount(1)
            ->and($runner->run())->toBe([$oauth->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }
    } finally {
        DB::purge();
    }
});

it('rolls back transactional DDL when a migration fails before it is recorded', function (): void {
    DB::purge();
    $factory = oauth21RollbackFactory('oauth-partial-failure');
    $connection = $factory->connection();
    $schema = new SchemaManager($connection);
    $migration = new class implements Migration {
        public function id(): string { return '20260827000000_oauth_partial_failure_probe'; }
        public function up(SchemaManager $schema, MigrationContext $context): void {
            $schema->create('oauth_partial_failure_probe', static function (Blueprint $table): void {
                $table->string('id', 64)->primary();
            });
            $context->checkpoint();
            throw new RuntimeException('synthetic OAuth migration failure');
        }
        public function down(SchemaManager $schema, MigrationContext $context): void {
            $schema->dropIfExists('oauth_partial_failure_probe');
            $context->checkpoint();
        }
    };
    $runner = new MigrationRunner($connection, [$migration]);

    try {
        expect(fn() => $runner->run())->toThrow(MigrationException::class)
            ->and($schema->hasTable('oauth_partial_failure_probe'))->toBeFalse()
            ->and($runner->status())->toBe([[
                'id' => $migration->id(),
                'applied' => false,
                'batch' => null,
            ]]);
    } finally {
        DB::purge();
    }
});

function oauth21RollbackFactory(string $name): DBLayerFactory
{
    return new DBLayerFactory(
        new DatabaseConnectionResolver(new ConfigRepository([
            'database' => [
                'default' => $name,
                'connections' => [$name => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ])),
        new RuntimeContextTracker(),
    );
}
