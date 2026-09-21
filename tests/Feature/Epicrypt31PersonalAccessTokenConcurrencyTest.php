<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Database\AuthSchema\AuthPersonalAccessTokenSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Tests\Fixtures\PatConcurrentWorker;

it('serializes PAT issue against concurrent revoke-all for the same subject', function (): void {
    $root = sys_get_temp_dir() . '/foundation-pat-race-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $database = $root . '/pat.sqlite';
    $start = $root . '/start';

    try {
        $dbFactory = PatConcurrentWorker::factory($database);
        $store = PatConcurrentWorker::store($database);
        $tables = new AuthTables();
        new MigrationRunner(
            $dbFactory->connection(),
            [new AuthPersonalAccessTokenSchema($tables)],
        )->run();

        // Establish the subject serialization row before the concurrent operations.
        // The race under test is issue() versus revokeAll(), not first-use row creation.
        expect($store->revokeAll('account-1', 1_699_999_999))->toBe(0);

        $commands = [];
        foreach (['issue', 'revoke'] as $operation) {
            $commands[$operation] = proc_open(
                [
                    PHP_BINARY,
                    '-r',
                    'require "vendor/autoload.php"; exit(\\Infocyph\\Foundation\\Tests\\Fixtures\\PatConcurrentWorker::run($argv[1], $argv[2], $argv[3]));',
                    $database,
                    $start,
                    $operation,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
            );
            expect($commands[$operation])->toBeResource();
            $commands[$operation] = [$commands[$operation], $pipes];
        }

        touch($start);
        foreach ($commands as [$process, $pipes]) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process), $stdout . $stderr)->toBe(0);
        }

        $record = $store->find('concurrent-token');
        expect($record)->not->toBeNull();

        $row = $dbFactory->connection()->select(
            sprintf('SELECT revision FROM %s WHERE subject_hash = ?', $tables->personalAccessTokenSubjects()),
            [hash('sha3-256', "foundation.personal-access-token.subject\0account-1")],
        )[0] ?? null;
        expect($row)->toBeArray()
            ->and((int) ($row['revision'] ?? 0))->toBe(3)
            ->and($record?->revokedAt === null || $record?->revokedAt === 1_700_000_100)->toBeTrue();
    } finally {
        foreach (glob($root . '/*') ?: [] as $path) {
            is_file($path) && unlink($path);
        }
        is_dir($root) && rmdir($root);
    }
});
