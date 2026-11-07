<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerAccountStore;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailDestinationChanged;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

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

        // Run the destination-conditional account mutation against the same
        // selected real SQLite/MySQL/PostgreSQL backend and installed schema.
        // This must not be a SQLite-only SQL grammar claim.
        $accounts = new DBLayerAccountStore(
            new RuntimeStateContainer([DBLayerFactory::class => $fixture->factory]),
            $fixture->tables,
        );
        $accounts->save(new Account('email-cas-direct', 'direct-cas@example.test', AccountStatus::PENDING_VERIFICATION));
        $accounts->save(new Account(
            'email-cas-username',
            'username-cas',
            AccountStatus::PENDING_VERIFICATION,
            metadata: ['email' => 'previous-cas@example.test', 'label' => 'preserved'],
        ));

        expect($accounts->markVerifiedForEmail('email-cas-direct', 'attacker@example.test', $now))->toBeFalse()
            ->and($accounts->markVerifiedForEmail('email-cas-direct', 'DIRECT-CAS@example.test', $now))->toBeTrue()
            ->and($accounts->findById('email-cas-direct')?->status())->toBe(AccountStatus::ACTIVE)
            ->and($accounts->markVerifiedForEmail('email-cas-username', 'attacker@example.test', $now))->toBeFalse();

        $accounts->updateMetadata('email-cas-username', [
            'email' => 'current-cas@example.test',
            'label' => 'preserved',
        ]);
        expect($accounts->markVerifiedForEmail('email-cas-username', 'previous-cas@example.test', $now))->toBeFalse()
            ->and($accounts->markVerifiedForEmail('email-cas-username', 'CURRENT-CAS@example.test', $now))->toBeTrue()
            ->and($accounts->findById('email-cas-username')?->status())->toBe(AccountStatus::ACTIVE)
            ->and($accounts->findById('email-cas-username')?->metadata()['label'] ?? null)->toBe('preserved')
            ->and($accounts->findById('email-cas-username')?->metadata()['verified_at'] ?? null)->toBe($now);

        // A real request-store transaction must commit successful verification
        // together with consumption and roll back a destination denial.
        $emailRequests = $fixture->store('email');
        $accounts->save(new Account(
            'email-cas-pending',
            'pending-cas',
            AccountStatus::PENDING_VERIFICATION,
            metadata: ['email' => 'pending-cas@example.test'],
        ));
        $emailRequests->save(new EmailVerificationRequest(
            'cas-success', 'email-cas-pending', 'pending-cas@example.test', $now, $now + 300,
        ));
        expect($emailRequests->consumeAndApply('cas-success', static function () use ($accounts, $now): void {
            if (!$accounts->markVerifiedForEmail('email-cas-pending', 'pending-cas@example.test', $now)) {
                throw new EmailDestinationChanged('Unexpected trusted destination rejection.');
            }
        }))->toBeTrue()
            ->and($emailRequests->find('cas-success')?->isConsumed())->toBeTrue()
            ->and($accounts->findById('email-cas-pending')?->status())->toBe(AccountStatus::ACTIVE);

        $accounts->save(new Account(
            'email-cas-stale',
            'stale-cas',
            AccountStatus::PENDING_VERIFICATION,
            metadata: ['email' => 'outdated-cas@example.test'],
        ));
        $emailRequests->save(new EmailVerificationRequest(
            'cas-stale', 'email-cas-stale', 'outdated-cas@example.test', $now, $now + 300,
        ));
        $accounts->updateMetadata('email-cas-stale', ['email' => 'changed-cas@example.test']);
        expect(fn() => $emailRequests->consumeAndApply('cas-stale', static function () use ($accounts, $now): void {
            if (!$accounts->markVerifiedForEmail('email-cas-stale', 'outdated-cas@example.test', $now)) {
                throw new EmailDestinationChanged('Verification destination changed before account mutation.');
            }
        }))->toThrow(EmailDestinationChanged::class)
            ->and($emailRequests->find('cas-stale')?->isConsumed())->toBeFalse()
            ->and($accounts->findById('email-cas-stale')?->status())->toBe(AccountStatus::PENDING_VERIFICATION)
            ->and($accounts->findById('email-cas-stale')?->metadata()['verified_at'] ?? null)->toBeNull();
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

it('restores retained auth records through independent SQLite/MySQL/PostgreSQL connections', function (string $driver): void {
    $directory = sys_get_temp_dir() . '/foundation-auth-restore-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to allocate the retained-data restore fixture.');
    }

    $configuration = persistedAuthConnection($driver, $directory);
    $fixture = new AuthContentionFixture($configuration);
    $database = $fixture->factory->connection();
    $schema = new SchemaManager($database);
    $tables = $fixture->tables;
    $created = false;
    $changed = null;
    $restorer = null;
    $verifier = null;

    try {
        expect($schema->hasTable($tables->accounts()))->toBeFalse();
        $created = true;
        new MigrationRunner($database, [new AuthSchema($tables)], table: 'foundation_auth_restore_migrations')->run();

        $now = time();
        $database->table($tables->accounts())->insert([
            'id' => 'retained-owner',
            'identifier' => 'original@example.test',
            'status' => 'active',
            'password_hash' => password_hash('retained-secret', PASSWORD_BCRYPT),
            'metadata' => '{"role":"owner"}',
        ]);
        $fixture->store('session')->create(
            new AuthSession('retained-session', 'retained-owner', null, $now, $now, $now + 3600),
        );
        $fixture->store('refresh')->save(
            new \Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord(
                hash('sha256', 'retained-refresh-token'), null, null,
                'retained-refresh', 'retained-owner', 'retained-family', $now, $now + 3600,
            ),
        );
        $fixture->store('passkey')->save(
            new PasskeyCredential('retained-passkey', 'retained-owner', 'opaque-credential-id', null, 'opaque-key', 7, [], $now),
        );

        // Capture a logical state snapshot before an independently committed
        // mutation, then restore that exact state through a new native DBLayer
        // connection. This does not claim an engine-level binary backup format.
        $identities = [
            $tables->accounts() => 'retained-owner',
            $tables->sessions() => 'retained-session',
            $tables->refreshTokens() => 'retained-refresh',
            $tables->passkeyCredentials() => 'retained-passkey',
        ];
        $snapshot = [];
        foreach ($identities as $table => $id) {
            $rows = $database->select("SELECT * FROM {$table} WHERE id = ?", [$id]);
            expect($rows)->toHaveCount(1);
            $snapshot[$table] = $rows[0];
        }
        $snapshotDigest = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        $changed = new \Infocyph\DBLayer\Connection\Connection(
            \Infocyph\DBLayer\Connection\ConnectionConfig::fromArray($configuration),
        );
        $changed->transaction(static function (\Infocyph\DBLayer\Connection\Connection $connection) use ($tables): void {
            $connection->statement(
                'UPDATE ' . $tables->accounts() . ' SET identifier = ?, password_hash = ? WHERE id = ?',
                ['modified@example.test', 'discarded', 'retained-owner'],
            );
            foreach ([$tables->passkeyCredentials(), $tables->refreshTokens(), $tables->sessions()] as $table) {
                $connection->statement("DELETE FROM {$table} WHERE id = ?", [
                    match ($table) {
                        $tables->passkeyCredentials() => 'retained-passkey',
                        $tables->refreshTokens() => 'retained-refresh',
                        default => 'retained-session',
                    },
                ]);
            }
        });
        $changed->disconnect();

        $restorer = new \Infocyph\DBLayer\Connection\Connection(
            \Infocyph\DBLayer\Connection\ConnectionConfig::fromArray($configuration),
        );
        $restorer->transaction(static function (\Infocyph\DBLayer\Connection\Connection $connection) use ($snapshot, $tables): void {
            $account = $snapshot[$tables->accounts()];
            $connection->statement(
                'UPDATE ' . $tables->accounts() . ' SET identifier = ?, password_hash = ?, metadata = ? WHERE id = ?',
                [$account['identifier'], $account['password_hash'], $account['metadata'], $account['id']],
            );
            foreach ([$tables->sessions(), $tables->refreshTokens(), $tables->passkeyCredentials()] as $table) {
                $row = $snapshot[$table];
                $columns = array_keys($row);
                $connection->statement(
                    sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $table,
                        implode(', ', $columns),
                        implode(', ', array_fill(0, count($columns), '?')),
                    ),
                    array_values($row),
                );
            }
        });
        $restorer->disconnect();

        $verifier = new \Infocyph\DBLayer\Connection\Connection(
            \Infocyph\DBLayer\Connection\ConnectionConfig::fromArray($configuration),
        );
        $restored = [];
        foreach ($identities as $table => $id) {
            $rows = $verifier->select("SELECT * FROM {$table} WHERE id = ?", [$id]);
            expect($rows)->toHaveCount(1);
            $restored[$table] = $rows[0];
        }

        expect(hash('sha256', json_encode($restored, JSON_THROW_ON_ERROR)))->toBe($snapshotDigest)
            ->and(password_verify('retained-secret', (string) $restored[$tables->accounts()]['password_hash']))->toBeTrue()
            ->and($fixture->store('session')->find('retained-session'))->not->toBeNull()
            ->and($fixture->store('refresh')->find('retained-refresh'))->not->toBeNull()
            ->and($fixture->store('passkey')->findByCredentialId('opaque-credential-id'))->not->toBeNull();
    } finally {
        $changed?->disconnect();
        $restorer?->disconnect();
        $verifier?->disconnect();
        if ($created) {
            foreach (array_reverse($tables->all()) as $table) {
                $schema->dropIfExists($table);
            }
            $schema->dropIfExists('foundation_auth_restore_migrations');
        }
        $database->disconnect();
        foreach (new FilesystemIterator($directory) as $file) {
            unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['sqlite', 'mysql', 'pgsql']);


it('backfills actual legacy passkey identities before Foundation 4 hash lookups on selected SQL engines', function (string $driver): void {
    $directory = sys_get_temp_dir() . '/foundation-legacy-passkey-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to allocate the isolated legacy passkey fixture.');
    }

    $config = persistedAuthConnection($driver, $directory);
    $fixture = new AuthContentionFixture($config);
    $database = $fixture->factory->connection();
    $schema = new SchemaManager($database);
    $tables = $fixture->tables;
    $created = false;

    try {
        expect($schema->hasTable($tables->accounts()))->toBeFalse();
        $created = true;
        new MigrationRunner($database, [new AuthSchema($tables)])->run();

        // Replace only the modern passkey table with the exact Foundation 3
        // column contract: a raw opaque ID unique key and no digest column.
        $schema->dropIfExists($tables->passkeyCredentials());
        $schema->create($tables->passkeyCredentials(), static function (\Infocyph\DBLayer\Schema\Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('credential_id', 1024)->unique();
            $table->text('credential_record')->nullable();
            $table->text('public_key');
            $table->bigInteger('sign_count');
            $table->json('transports')->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('last_used_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->bigInteger('revision')->default(0);
        });

        $now = time();
        $rawIds = ['legacy-one-' . bin2hex(random_bytes(12)), 'legacy-two-' . bin2hex(random_bytes(12))];
        foreach ($rawIds as $offset => $raw) {
            $database->table($tables->passkeyCredentials())->insert([
                'id' => 'legacy-passkey-' . $offset,
                'account_id' => 'legacy-owner',
                'credential_id' => $raw,
                'credential_record' => null,
                'public_key' => 'opaque-legacy-public-key',
                'sign_count' => $offset,
                'transports' => '["internal"]',
                'created_at' => $now,
                'last_used_at' => null,
                'revoked_at' => null,
                'metadata' => null,
                'revision' => 0,
            ]);
        }
        expect($schema->hasColumn($tables->passkeyCredentials(), 'credential_id_hash'))->toBeFalse();

        $installer = new \Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller(
            $fixture->factory,
            new AuthSchema($tables),
            new \Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema($tables),
            new \Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRevisionSchema($tables),
            new \Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRecordSchema($tables),
            $tables,
        );
        $installer->install();
        expect($installer->installed())->toBeTrue()
            ->and($schema->hasColumn($tables->passkeyCredentials(), 'credential_id_hash'))->toBeTrue();

        foreach ($rawIds as $offset => $raw) {
            $record = $fixture->store('passkey')->findByCredentialId($raw);
            expect($record?->id)->toBe('legacy-passkey-' . $offset)
                ->and($record?->credentialId)->toBe($raw)
                ->and($database->table($tables->passkeyCredentials())->where('id', '=', 'legacy-passkey-' . $offset)->value('credential_id_hash'))
                ->toBe(hash('sha3-256', $raw));
        }

        // Re-running the installer cannot rewrite or lose historical keys.
        $installer->install();
        expect($fixture->store('passkey')->findByCredentialId($rawIds[0])?->id)->toBe('legacy-passkey-0')
            ->and($fixture->store('passkey')->findByCredentialId($rawIds[1])?->id)->toBe('legacy-passkey-1');
    } finally {
        if ($created) {
            foreach (array_reverse($tables->all()) as $table) {
                $schema->dropIfExists($table);
            }
            $schema->dropIfExists('migrations');
        }
        $database->disconnect();
        foreach (new FilesystemIterator($directory) as $file) {
            unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['sqlite', 'mysql', 'pgsql']);
