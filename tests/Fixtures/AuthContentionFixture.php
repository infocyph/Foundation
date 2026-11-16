<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEmailVerificationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasskeyCredentialStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasswordResetStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRememberTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerSessionStore;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
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

    /**
     * @param array<string, mixed> $connection
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(array $connection)
    {
        $this->factory = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
            'database' => ['default' => 'race', 'connections' => ['race' => $connection]],
        ])), RuntimeStateContainer::execution());
        $this->factory->connection()->setQueryTimeoutMs(5000);
        $this->tables = new AuthTables();
    }

    /** @return ($kind is 'refresh' ? RefreshTokenRecord : RememberTokenRecord) */
    public static function token(string $kind, string $id, int $now): RefreshTokenRecord|RememberTokenRecord
    {
        return $kind === 'refresh'
            ? new RefreshTokenRecord(hash('sha256', $id), null, null, $id, 'owner', 'family', $now, $now + 300)
            : new RememberTokenRecord('device', $id, hash('sha256', $id), $id, 'owner', 'family', $now, $now + 300);
    }

    /**
     * @throws \RuntimeException
     */
    public static function waitFor(string $path): void
    {
        $until = microtime(true) + 15.0;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $until);

        throw new RuntimeException('Auth contention barrier timed out: ' . basename($path));
    }

    /**
     * @throws \RuntimeException
     */
    public function compete(string $kind, string $directory, string $worker, int $now): bool
    {
        $current = match ($kind) {
            'reset' => $this->store('reset')->find('source'),
            'email' => $this->store('email')->find('source'),
            'refresh' => $this->store('refresh')->find('source'),
            'remember' => $this->store('remember')->find('source'),
            'session' => $this->store('session')->find('source'),
            'passkey' => $this->store('passkey')->findByCredentialId('credential'),
            default => throw new RuntimeException('Unknown auth contention subject.'),
        };
        if ($current === null) {
            throw new RuntimeException('Missing auth contention source record.');
        }
        touch($directory . '/ready-' . $worker);
        self::waitFor($directory . '/start');

        return $this->apply($kind, $current, $worker, $now);
    }

    /**
     * @return ($kind is 'reset' ? DBLayerPasswordResetStore : ($kind is 'email' ? DBLayerEmailVerificationStore : ($kind is 'refresh' ? DBLayerRefreshTokenStore : ($kind is 'remember' ? DBLayerRememberTokenStore : ($kind is 'session' ? DBLayerSessionStore : DBLayerPasskeyCredentialStore)))))
     */
    public function store(string $kind): DBLayerPasswordResetStore|DBLayerEmailVerificationStore|DBLayerRefreshTokenStore|DBLayerRememberTokenStore|DBLayerSessionStore|DBLayerPasskeyCredentialStore
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

    /** @throws \RuntimeException */
    private function apply(
        string $kind,
        PasswordResetRequest|EmailVerificationRequest|RefreshTokenRecord|RememberTokenRecord|AuthSession|PasskeyCredential $current,
        string $worker,
        int $now,
    ): bool {
        return match ($kind) {
            'reset' => $this->store('reset')->consumeAndApply('source', fn() => $this->changePassword($worker)),
            'email' => $this->store('email')->consumeAndApply('source', fn() => $this->changePassword($worker)),
            'refresh' => $current instanceof RefreshTokenRecord
                ? $this->store('refresh')->rotateIfActive($current, self::token('refresh', $worker, $now))
                : throw new RuntimeException('Unexpected refresh source record.'),
            'remember' => $current instanceof RememberTokenRecord
                ? $this->store('remember')->rotateIfActive($current, self::token('remember', $worker, $now))
                : throw new RuntimeException('Unexpected remember source record.'),
            'session' => $current instanceof AuthSession
                ? $this->store('session')->rotate($current, new AuthSession($worker, 'owner', null, $now, $now, $now + 300), $now)
                : throw new RuntimeException('Unexpected session source record.'),
            'passkey' => $current instanceof PasskeyCredential
                ? $this->store('passkey')->compareAndSwap($current, $current->revokedAt($now))
                : throw new RuntimeException('Unexpected passkey source record.'),
            default => throw new RuntimeException('Unknown auth contention subject.'),
        };
    }

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function changePassword(string $worker): void
    {
        $this->factory->connection()->table($this->tables->accounts())
            ->where('id', '=', 'owner')->update(['password_hash' => $worker]);
    }
}
