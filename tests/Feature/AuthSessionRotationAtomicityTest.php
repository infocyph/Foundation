<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerSessionStore;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Exception\SessionException;
use Infocyph\Foundation\Auth\Support\InMemorySessionStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\Resolver\SessionPrincipalResolver;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

/**
 * @throws \Infocyph\Foundation\Exception\ConfigurationException
 * @throws \LogicException
 * @throws \Psr\Container\ContainerExceptionInterface
 * @throws \Psr\Container\NotFoundExceptionInterface
 */
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

    return new DBLayerSessionStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables);
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
    \PHPUnit\Framework\Assert::assertNotNull($fresh);
    expect($store->rotate($fresh, $second, $now + 301))->toBeFalse()
        ->and($store->rotate($fresh, new AuthSession('foreign', 'other', null, $now, $now, $now + 300), $now))->toBeFalse()
        ->and($store->find('original'))->not->toBeNull();
})->with(['memory', 'sqlite']);

it('does not resurrect a session revoked while manager rotation is in flight', function (string $backend): void {
    $store = atomicAuthSessionStore($backend);
    $barrier = new class ($store) implements SessionStoreInterface {
        public ?AuthSession $replacement = null;

        public function __construct(private SessionStoreInterface $store) {}

        #[\Override]
        public function create(AuthSession $session): void
        {
            $this->store->create($session);
        }

        #[\Override]
        public function find(string $sessionId): ?AuthSession
        {
            return $this->store->find($sessionId);
        }

        #[\Override]
        public function revoke(string $sessionId): void
        {
            $this->store->revoke($sessionId);
        }

        #[\Override]
        public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void
        {
            $this->store->revokeAllForAccount($accountId, $exceptSessionId);
        }

        #[\Override]
        public function rotate(AuthSession $current, AuthSession $replacement, int $now): bool
        {
            $this->replacement = $replacement;
            expect(Fiber::suspend())->toBeNull();

            return $this->store->rotate($current, $replacement, $now);
        }

        #[\Override]
        public function touch(string $sessionId, int $lastSeenAt): void
        {
            $this->store->touch($sessionId, $lastSeenAt);
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
        expect(fn(): mixed => $rotation->resume())->toThrow(SessionException::class, 'revoked during rotation')
            ->and($store->find('race'))->toBeNull()
            ->and($barrier->replacement)->not->toBeNull()
            ->and($store->find($barrier->replacement->id ?? 'missing'))->toBeNull();
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

it('preserves OIDC authentication evidence through rotation and native session principal resolution', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now);
    $store = new InMemorySessionStore();
    $authenticatedAt = $now - 3_600;
    $stored = new AuthSession(
        'verified-session',
        'account-1',
        'device-1',
        $now - 5_000,
        $now - 5_000,
        $now + 5_000,
        recentAuthAt: $authenticatedAt,
    );
    $store->create($stored);
    $config = new ConfigRepository();
    $services = new RuntimeStateContainer([
        SessionStoreInterface::class => $store,
        AccountProviderInterface::class => $fixture->accounts,
        ClockInterface::class => $fixture->clock,
    ]);
    $resolver = new SessionPrincipalResolver($config, $services);
    $manager = new SessionManager($store, new UidAuthIdGenerator($config), clock: $fixture->clock);

    try {
        $original = $resolver->resolve(\Infocyph\Webrick\Request\Request::fake(
            headers: ['Host' => 'issuer.example.test', 'X-Session-Id' => $stored->id],
            uri: 'https://issuer.example.test/profile',
        ));
        expect($original?->metadata()['auth_time'] ?? null)->toBe($authenticatedAt);

        $rotated = $manager->rotate($stored->id);
        expect($rotated->recentAuthAt)->toBe($authenticatedAt)
            ->and($rotated->createdAt)->toBe($now)
            ->and($store->find($stored->id))->toBeNull();
        $current = $resolver->resolve(\Infocyph\Webrick\Request\Request::fake(
            headers: ['Host' => 'issuer.example.test', 'X-Session-Id' => $rotated->id],
            uri: 'https://issuer.example.test/profile',
        ));
        expect($current?->metadata()['auth_time'] ?? null)->toBe($authenticatedAt)
            ->and($current?->metadata()['auth_via'] ?? null)->toBe('session')
            ->and($current?->metadata()['session_id'] ?? null)->toBe($rotated->id);

        $store->create(new AuthSession('unknown-auth', 'account-1', null, $now - 100, $now - 100, $now + 5_000));
        $unknown = $manager->rotate('unknown-auth');
        expect($unknown->recentAuthAt)->toBeNull();
        $unverified = $resolver->resolve(\Infocyph\Webrick\Request\Request::fake(
            headers: ['Host' => 'issuer.example.test', 'X-Session-Id' => $unknown->id],
            uri: 'https://issuer.example.test/profile',
        ));
        expect($unverified?->metadata()['auth_time'] ?? null)->toBeNull();
    } finally {
        $fixture->close();
    }
});
