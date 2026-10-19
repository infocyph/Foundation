<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;

/** @return array<string, mixed> */
function persistedAuthConnection(string $driver, string $directory): array
{
    if ($driver === 'sqlite') {
        return ['driver' => 'sqlite', 'database' => $directory . '/auth.sqlite', 'options' => [PDO::ATTR_TIMEOUT => 10], 'security' => ['max_param_bytes' => 16_384]];
    }
    $key = $driver === 'mysql' ? 'MYSQL' : 'POSTGRES';
    $dsn = getenv('IC_' . $key . '_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, $driver . ':')) {
        throw new RuntimeException('Persisted auth contention requires IC_' . $key . '_DSN.');
    }
    $parts = [];
    foreach (explode(';', substr($dsn, strlen($driver) + 1)) as $piece) {
        if (str_contains($piece, '=')) {
            [$keyName, $value] = explode('=', $piece, 2);
            $parts[$keyName] = $value;
        }
    }

    return [
        'driver' => $driver,
        'security' => ['max_param_bytes' => 16_384],
        'host' => $parts['host'] ?? '127.0.0.1',
        'port' => (int) ($parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432)),
        'database' => $parts['dbname'] ?? throw new RuntimeException('Missing contention database.'),
        'username' => getenv('IC_' . $key . '_USER'),
        'password' => getenv('IC_' . $key . '_PASSWORD'),
    ];
}

/** @return array{resource, resource, resource} */
function startPersistedAuthWorker(string $directory, string $kind, string $worker, int $now): array
{
    $process = proc_open([
        PHP_BINARY, dirname(__DIR__) . '/Fixtures/auth_contention_worker.php', $directory, $kind, $worker, (string) $now,
    ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start independent auth process.');
    }
    fclose($pipes[0]);

    return [$process, $pipes[1], $pipes[2]];
}

it('commits one persisted auth transition across independent competing processes', function (string $driver): void {
    $directory = sys_get_temp_dir() . '/foundation-auth-process-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $configuration = persistedAuthConnection($driver, $directory);
    file_put_contents($directory . '/connection.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixture = new AuthContentionFixture($configuration);
    $db = $fixture->factory->connection();
    $schema = new SchemaManager($db);
    $runner = new MigrationRunner($db, [new AuthSchema($fixture->tables)], table: 'foundation_auth_contention_migrations');
    $created = false;
    $processes = [];
    $now = time();

    try {
        // Never repurpose or remove an application's pre-existing auth schema.
        expect($schema->hasTable($fixture->tables->accounts()))->toBeFalse();
        $created = true;
        $runner->run();
        $db->table($fixture->tables->accounts())->insert([
            'id' => 'owner', 'identifier' => 'owner@example.test', 'status' => 'active', 'password_hash' => 'before',
        ]);
        foreach (['reset', 'email', 'refresh', 'remember', 'session', 'passkey'] as $kind) {
            $store = $fixture->store($kind);
            $record = match ($kind) {
                'reset' => new PasswordResetRequest('source', 'owner', $now, $now + 300),
                'email' => new EmailVerificationRequest('source', 'owner', 'owner@example.test', $now, $now + 300),
                'refresh', 'remember' => AuthContentionFixture::token($kind, 'source', $now),
                'session' => new AuthSession('source', 'owner', null, $now, $now, $now + 300),
                'passkey' => new PasskeyCredential('source', 'owner', 'credential', null, 'key', 0, [], $now),
            };
            $kind === 'session' ? $store->create($record) : $store->save($record);
            foreach (['first', 'second'] as $worker) {
                $processes[] = startPersistedAuthWorker($directory, $kind, $worker, $now);
            }
            AuthContentionFixture::waitFor($directory . '/ready-first');
            AuthContentionFixture::waitFor($directory . '/ready-second');
            touch($directory . '/start');
            $results = [];
            foreach ($processes as $index => [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout);
                $error = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                $exit = proc_close($process);
                unset($processes[$index]);
                $resultPath = $directory . '/result-' . ($index === 0 ? 'first' : 'second');
                $diagnostic = is_file($resultPath) ? file_get_contents($resultPath) : '';
                if ($exit !== 0) {
                    throw new RuntimeException($kind . ': ' . $error . $output . $diagnostic);
                }
                expect($exit)->toBe(0);
            }
            $processes = [];
            foreach (['first', 'second'] as $worker) {
                $results[$worker] = json_decode((string) file_get_contents($directory . '/result-' . $worker), true, flags: JSON_THROW_ON_ERROR)['changed'];
            }
            expect(array_sum($results), $driver . ':' . $kind)->toBe(1);
            $winner = array_search(true, $results, true);
            if ($kind === 'reset' || $kind === 'email') {
                expect($db->table($fixture->tables->accounts())->where('id', '=', 'owner')->value('password_hash'))->toBe($winner);
            } elseif ($kind === 'passkey') {
                expect($store->findByCredentialId('credential'))->toBeNull();
            } else {
                expect($store->find((string) $winner))->not->toBeNull();
            }
            foreach (new FilesystemIterator($directory) as $file) {
                if (str_starts_with($file->getFilename(), 'ready-') || str_starts_with($file->getFilename(), 'result-') || $file->getFilename() === 'start') {
                    unlink($file->getPathname());
                }
            }
        }

        // The child has already read the source when another connection revokes it.
        $sessions = $fixture->store('session');
        $sessions->create(new AuthSession('source', 'owner', null, $now, $now, $now + 300));
        $processes[] = startPersistedAuthWorker($directory, 'session', 'first', $now);
        AuthContentionFixture::waitFor($directory . '/ready-first');
        $sessions->revokeAllForAccount('owner');
        touch($directory . '/start');
        [$process, $stdout, $stderr] = array_shift($processes);
        $output = stream_get_contents($stdout) . stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        expect(proc_close($process))->toBe(0)
            ->and($output)->toBe('')
            ->and(json_decode((string) file_get_contents($directory . '/result-first'), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['changed' => false])
            ->and($db->table($fixture->tables->sessions())->count())->toBe(0);

        $reset = $fixture->store('reset');
        $reset->save(new PasswordResetRequest('rollback', 'owner', $now, $now + 300));
        $before = $db->table($fixture->tables->accounts())->where('id', '=', 'owner')->value('password_hash');
        expect(fn() => $reset->consumeAndApply('rollback', static function () use ($db, $fixture): void {
            $db->table($fixture->tables->accounts())->where('id', '=', 'owner')->update(['password_hash' => 'uncommitted']);
            throw new RuntimeException('injected account mutation failure');
        }))->toThrow(\Infocyph\DBLayer\Exceptions\TransactionException::class)
            ->and($reset->wasConsumed('rollback'))->toBeFalse()
            ->and($db->table($fixture->tables->accounts())->where('id', '=', 'owner')->value('password_hash'))->toBe($before);

        $credentials = $fixture->store('passkey');
        // Explicit native parameter budget covers opaque credentials plus audit JSON.
        $opaque = str_repeat('A', 2048);
        $credentials->save(new PasskeyCredential('long-upper', 'owner', $opaque, null, 'key', 0, [], $now));
        $credentials->save(new PasskeyCredential('long-lower', 'owner', strtolower($opaque), null, 'key', 0, [], $now));
        expect($credentials->findByCredentialId($opaque)?->id)->toBe('long-upper')
            ->and($credentials->findByCredentialId(strtolower($opaque))?->id)->toBe('long-lower');
        $app = \Infocyph\Foundation\Foundation::cli([
            'auth' => ['drivers' => ['storage' => 'database']],
            'database' => ['default' => 'auth', 'connections' => ['auth' => $configuration]],
        ])->boot();
        $app->execution()->run(static function () use ($app, $opaque, $credentials): void {
            $manager = $app->make(\Infocyph\Foundation\Auth\AuthServices::class)->passkeys();
            expect(fn() => $manager->revokeCredential('other-account', $opaque))->toThrow(InvalidArgumentException::class)
                ->and($credentials->findByCredentialId($opaque)?->accountId)->toBe('owner');
            $manager->revokeCredential('owner', $opaque);
            expect($credentials->findByCredentialId($opaque))->toBeNull()
                ->and($credentials->findByCredentialId(strtolower($opaque)))->not->toBeNull();
        });
    } finally {
        foreach ($processes as [$process, $stdout, $stderr]) {
            proc_terminate($process);
            fclose($stdout);
            fclose($stderr);
            proc_close($process);
        }
        if ($created) {
            foreach (array_reverse($fixture->tables->all()) as $table) {
                $schema->dropIfExists($table);
            }
            $schema->dropIfExists('foundation_auth_contention_migrations');
        }
        $db->disconnect();
        foreach (new FilesystemIterator($directory) as $file) {
            unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['sqlite', 'mysql', 'pgsql']);
