<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEmailVerificationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasskeyCredentialStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasswordResetStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRememberTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerSessionStore;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use RuntimeException;

/** Independent connections and bounded process barriers for persisted auth races. */
final readonly class AuthContentionFixture
{
    public DBLayerFactory $factory;
    public AuthTables $tables;

    /** @param array<string, mixed> $connection */
    public function __construct(array $connection)
    {
        $this->factory = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
            'database' => ['default' => 'race', 'connections' => ['race' => $connection]],
        ])), RuntimeStateContainer::execution());
        $this->factory->connection()->setQueryTimeoutMs(5000);
        $this->tables = new AuthTables();
    }

    public function store(string $kind): mixed
    {
        $clock = new SystemClock();

        return match ($kind) {
            'reset' => new DBLayerPasswordResetStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables, $clock),
            'email' => new DBLayerEmailVerificationStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables, $clock),
            'refresh' => new DBLayerRefreshTokenStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables, $clock),
            'remember' => new DBLayerRememberTokenStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables, $clock),
            'session' => new DBLayerSessionStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables),
            'passkey' => new DBLayerPasskeyCredentialStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $this->factory]), $this->tables, $clock),
            default => throw new RuntimeException('Unknown auth contention subject.'),
        };
    }

    public static function token(string $kind, string $id, int $now): RefreshTokenRecord|RememberTokenRecord
    {
        return $kind === 'refresh'
            ? new RefreshTokenRecord(hash('sha256', $id), null, null, $id, 'owner', 'family', $now, $now + 300)
            : new RememberTokenRecord('device', $id, hash('sha256', $id), $id, 'owner', 'family', $now, $now + 300);
    }

    public static function waitFor(string $path): void
    {
        $until = microtime(true) + 15;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $until);

        throw new RuntimeException('Auth contention barrier timed out: ' . basename($path));
    }

    public function compete(string $kind, string $directory, string $worker, int $now): bool
    {
        $store = $this->store($kind);
        $current = $kind === 'passkey' ? $store->findByCredentialId('credential') : $store->find('source');
        if ($current === null) {
            throw new RuntimeException('Missing auth contention source record.');
        }
        touch($directory . '/ready-' . $worker);
        self::waitFor($directory . '/start');

        return match ($kind) {
            'reset', 'email' => $store->consumeAndApply('source', function () use ($worker): void {
                $this->factory->connection()->table($this->tables->accounts())
                    ->where('id', '=', 'owner')->update(['password_hash' => $worker]);
            }),
            'refresh', 'remember' => $store->rotateIfActive($current, self::token($kind, $worker, $now)),
            'session' => $store->rotate($current, new AuthSession($worker, 'owner', null, $now, $now, $now + 300), $now),
            'passkey' => $store->compareAndSwap($current, $current->revokedAt($now)),
        };
    }
}
