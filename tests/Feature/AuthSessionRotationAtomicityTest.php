<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerSessionStore;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Exception\SessionException;
use Infocyph\Foundation\Auth\Support\InMemorySessionStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

function atomicAuthSessionStore(string $backend): SessionStoreInterface
{
    if ($backend === 'memory') {
        return new InMemorySessionStore();
    }

    $config = new ConfigRepository(['database' => [
        'default' => 'auth-rotation',
        'connections' => ['auth-rotation' => ['driver' => 'sqlite', 'database' => ':memory:']],
    ]]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    new MigrationRunner($factory->connection(), [new AuthSchema($tables)])->run();

    return new DBLayerSessionStore($factory, $tables);
}

it('allows only one rotation and rejects revoked changed expired and cross-account sessions', function (string $backend): void {
    $store = atomicAuthSessionStore($backend);
    $now = time();
    $original = new AuthSession('original', 'owner', null, $now, $now, $now + 300);
    $first = new AuthSession('first', 'owner', null, $now, $now, $now + 300);
    $second = new AuthSession('second', 'owner', null, $now, $now, $now + 300);
    $store->create($original);
    expect($store->rotate($original, $first, $now))->toBeTrue()
        ->and($store->rotate($original, $second, $now))->toBeFalse()
        ->and($store->find('second'))->toBeNull();

    $store->revokeAllForAccount('owner');
    expect($store->rotate($first, $second, $now))->toBeFalse()
        ->and($store->find('second'))->toBeNull();

    $store->create($original);
    $store->touch('original', $now + 1);
    expect($store->rotate($original, $second, $now))->toBeFalse();
    $fresh = $store->find('original');
    expect($fresh)->not->toBeNull();
    if ($fresh === null) {
        throw new RuntimeException('Session fixture disappeared.');
    }
    expect($store->rotate($fresh, $second, $now + 301))->toBeFalse()
        ->and($store->rotate($fresh, new AuthSession('foreign', 'other', null, $now, $now, $now + 300), $now))->toBeFalse()
        ->and($store->find('original'))->not->toBeNull();
})->with(['memory', 'sqlite']);

it('does not resurrect a session revoked while manager rotation is in flight', function (string $backend): void {
    $store = atomicAuthSessionStore($backend);
    $barrier = new class($store) implements SessionStoreInterface {
        public ?AuthSession $replacement = null;
        public function __construct(private SessionStoreInterface $store) {}
        public function create(AuthSession $session): void { $this->store->create($session); }
        public function find(string $sessionId): ?AuthSession { return $this->store->find($sessionId); }
        public function revoke(string $sessionId): void { $this->store->revoke($sessionId); }
        public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void { $this->store->revokeAllForAccount($accountId, $exceptSessionId); }
        public function touch(string $sessionId, int $lastSeenAt): void { $this->store->touch($sessionId, $lastSeenAt); }
        public function rotate(AuthSession $current, AuthSession $replacement, int $now): bool
        {
            $this->replacement = $replacement;
            Fiber::suspend();

            return $this->store->rotate($current, $replacement, $now);
        }
    };
    $now = time();
    $store->create(new AuthSession('race', 'owner', null, $now, $now, $now + 300));
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app, $store, $barrier): void {
        $manager = new SessionManager($barrier, $app->make(AuthIdGeneratorInterface::class));
        $rotation = new Fiber(static fn(): AuthSession => $manager->rotate('race'));
        $rotation->start();
        $store->revokeAllForAccount('owner');
        expect(fn() => $rotation->resume())->toThrow(SessionException::class, 'revoked during rotation')
            ->and($store->find('race'))->toBeNull()
            ->and($barrier->replacement)->not->toBeNull()
            ->and($store->find($barrier->replacement?->id ?? 'missing'))->toBeNull();
    });
})->with(['memory', 'sqlite']);

it('preserves the original persisted session when replacement insertion fails', function (): void {
    $store = atomicAuthSessionStore('sqlite');
    $now = time();
    $original = new AuthSession('original', 'owner', null, $now, $now, $now + 300);
    $occupied = new AuthSession('occupied', 'other', null, $now, $now, $now + 300);
    $store->create($original);
    $store->create($occupied);
    expect(fn() => $store->rotate($original, new AuthSession('occupied', 'owner', null, $now, $now, $now + 300), $now))
        ->toThrow(\Infocyph\DBLayer\Exceptions\TransactionException::class)
        ->and($store->find('original'))->toEqual($original)
        ->and($store->find('occupied'))->toEqual($occupied);
});
