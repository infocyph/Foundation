<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\Middleware\SessionMiddleware;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Foundation\Session\SessionStoreInterface;
use Infocyph\Foundation\Session\Store\ArraySessionStore;
use Infocyph\Foundation\Session\Store\CacheSessionStore;
use Infocyph\Foundation\Session\Store\DatabaseSessionStore;
use Infocyph\Foundation\Session\Store\FileSessionStore;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('persists session data and expires flash data after its next request', function (): void {
    [$middleware] = browserSessionStack();

    $first = $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/first'),
        static function (Request $request): Response {
            $session = BrowserSession::fromRequest($request);
            $session->put('user_id', 42);
            $session->flash('notice', 'saved');

            return Response::json(['token' => $session->csrfToken()]);
        },
    );
    $id = browserSessionCookieId($first);

    $second = $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/second')
            ->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => Response::json([
            'user_id' => BrowserSession::fromRequest($request)->get('user_id'),
            'notice' => BrowserSession::fromRequest($request)->get('notice'),
        ]),
    );
    $third = $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/third')
            ->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => Response::json([
            'user_id' => BrowserSession::fromRequest($request)->get('user_id'),
            'notice' => BrowserSession::fromRequest($request)->get('notice'),
        ]),
    );

    expect(browserSessionJson($second))->toBe(['user_id' => 42, 'notice' => 'saved'])
        ->and(browserSessionJson($third))->toBe(['user_id' => 42, 'notice' => null])
        ->and($first->getHeaderLine('Set-Cookie'))->toContain(
            'Secure',
            'HttpOnly',
            'SameSite=Lax',
        );
});

it('regenerates identifiers without retaining the old session record', function (): void {
    [$middleware, $manager, $store] = browserSessionStack();

    $first = $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/login'),
        static function (Request $request): Response {
            BrowserSession::fromRequest($request)->put('before', true);

            return Response::json(['ok' => true]);
        },
    );
    $oldId = browserSessionCookieId($first);

    $second = $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/rotate')
            ->withCookieParams(['infbyte_session' => $oldId]),
        static function (Request $request): Response {
            $session = BrowserSession::fromRequest($request);
            $session->regenerate();

            return Response::json(['before' => $session->get('before')]);
        },
    );
    $newId = browserSessionCookieId($second);

    expect($newId)->not->toBe($oldId)
        ->and($store->load($oldId, time()))->toBeNull()
        ->and($manager->open($newId)->get('before'))->toBeTrue();
});

it('accepts CSRF tokens only from the configured header or body', function (): void {
    [$sessionMiddleware, , $store, $config] = browserSessionStack();
    $csrf = new CsrfMiddleware($config);

    $bootstrap = $sessionMiddleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/form'),
        static fn(Request $request): Response => Response::json([
            'token' => BrowserSession::fromRequest($request)->csrfToken(),
        ]),
    );
    $id = browserSessionCookieId($bootstrap);
    $token = browserSessionJson($bootstrap)['token'];

    $header = $sessionMiddleware(
        Request::fake(
            headers: ['Host' => 'example.test', 'Origin' => 'https://example.test', 'X-CSRF-Token' => $token],
            method: 'POST',
            uri: 'https://example.test/save',
        )->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => $csrf($request, static fn(): Response => Response::json(['ok' => true])),
    );
    $body = $sessionMiddleware(
        Request::fake(
            post: ['_token' => $token],
            headers: ['Host' => 'example.test'],
            method: 'POST',
            uri: 'https://example.test/save',
        )->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => $csrf($request, static fn(): Response => Response::json(['ok' => true])),
    );
    $query = $sessionMiddleware(
        Request::fake(
            query: ['_token' => $token],
            headers: ['Host' => 'example.test'],
            method: 'POST',
            uri: 'https://example.test/save',
        )->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => $csrf($request, static fn(): Response => Response::json(['ok' => true])),
    );
    $foreignOrigin = $sessionMiddleware(
        Request::fake(
            headers: ['Host' => 'example.test', 'Origin' => 'https://attacker.test', 'X-CSRF-Token' => $token],
            method: 'POST',
            uri: 'https://example.test/save',
        )->withCookieParams(['infbyte_session' => $id]),
        static fn(Request $request): Response => $csrf($request, static fn(): Response => Response::json(['ok' => true])),
    );

    expect($header->getStatusCode())->toBe(200)
        ->and($body->getStatusCode())->toBe(200)
        ->and($query->getStatusCode())->toBe(419)
        ->and($foreignOrigin->getStatusCode())->toBe(419)
        ->and($store->load($id, time()))->not->toBeNull();
});

it('does not activate session services for routes that do not select them', function (): void {
    $project = browserSessionProject([
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/lean', static fn(): Response => Response::json(['ok' => true]));
Router::get('/state', static function (BrowserSession $session): Response {
    $session->put('visited', true);

    return Response::json(['has' => true]);
}, ['middleware' => ['session']]);
PHP,
    ]);

    try {
        $app = Foundation::web([
            'base_path' => $project,
            'session' => ['driver' => 'array'],
        ]);

        expect($app->container()->has(SessionManager::class))->toBeFalse();
        $leanResponse = $app->handle(Request::fake(
            headers: ['Host' => 'example.test'],
            uri: 'https://example.test/lean',
        ));
        expect($leanResponse->getStatusCode())->toBe(200)
            ->and($app->container()->has(SessionManager::class))->toBeFalse();

        $stateResponse = $app->handle(Request::fake(
            headers: ['Host' => 'example.test'],
            uri: 'https://example.test/state',
        ));
        expect($stateResponse->getStatusCode())->toBe(200)
            ->and(browserSessionJson($stateResponse))->toBe(['has' => true])
            ->and($app->container()->has(SessionManager::class))->toBeTrue();
    } finally {
        browserSessionRemoveDirectory($project);
    }
});

it('persists payloads through file and cache stores and prunes expired files', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-session-store-' . bin2hex(random_bytes(5));
    $payload = new \Infocyph\Foundation\Session\SessionPayload(
        ['account' => 7],
        ['notice'],
        time() + 60,
    );

    try {
        $file = new FileSessionStore($directory);
        $file->save(str_repeat('a', 64), $payload);
        $cache = new CacheSessionStore(Cache::memory('foundation-session-test'));
        $cache->save(str_repeat('b', 64), $payload);

        expect($file->load(str_repeat('a', 64), time())?->data)->toBe(['account' => 7])
            ->and($cache->load(str_repeat('b', 64), time())?->flashKeys)->toBe(['notice']);

        $file->save(str_repeat('c', 64), new \Infocyph\Foundation\Session\SessionPayload([], [], time() - 1));
        expect($file->prune(time()))->toBe(1);
    } finally {
        browserSessionRemoveDirectory($directory);
    }
});

it('creates and uses the portable DBLayer session schema on SQLite', function (): void {
    if (!extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available.');
    }

    $project = sys_get_temp_dir() . '/foundation-session-db-' . bin2hex(random_bytes(5));
    mkdir($project . '/database', 0775, true);

    try {
        $app = Foundation::console([
            'base_path' => $project,
            'session' => [
                'driver' => 'database',
                'stores' => [
                    'database' => [
                        'connection' => 'session',
                        'table' => 'browser_sessions',
                    ],
                ],
            ],
            'database' => [
                'default' => 'session',
                'connections' => [
                    'session' => [
                        'driver' => 'sqlite',
                        'database' => 'database/session.sqlite',
                    ],
                ],
            ],
        ]);
        $schema = $app->make(\Infocyph\Foundation\Session\SessionDatabaseSchema::class);
        $schema->install();
        $connection = $app->db()->connection('session');
        $store = new DatabaseSessionStore($connection, 'browser_sessions');
        $id = str_repeat('d', 64);
        $payload = new \Infocyph\Foundation\Session\SessionPayload(['role' => 'admin'], [], time() + 60);
        $store->save($id, $payload);

        expect($schema->readiness()['installed'])->toBeTrue()
            ->and($store->load($id, time())?->data)->toBe(['role' => 'admin']);

        $store->save(str_repeat('e', 64), new \Infocyph\Foundation\Session\SessionPayload([], [], time() - 1));
        expect($store->prune(time(), 1))->toBe(1);
    } finally {
        \Infocyph\DBLayer\DB::purge();
        browserSessionRemoveDirectory($project);
    }
});

it('reports file session persistence failures without leaking PHP warnings', function (): void {
    $payload = new \Infocyph\Foundation\Session\SessionPayload(['account' => 7], [], time() + 60);
    $directory = sys_get_temp_dir() . '/foundation-session-read-only-' . bin2hex(random_bytes(5));
    mkdir($directory, 0500, true);

    try {
        if (is_writable($directory)) {
            chmod($directory, 0700);
            test()->markTestSkipped('The current user can write to permission-restricted directories.');
        }

        expect(fn() => (new FileSessionStore($directory))->save(str_repeat('a', 64), $payload))
            ->toThrow(RuntimeException::class, 'Unable to write session file');
    } finally {
        chmod($directory, 0700);
        browserSessionRemoveDirectory($directory);
    }
});

it('reports cache session persistence failures', function (): void {
    $payload = new \Infocyph\Foundation\Session\SessionPayload(['account' => 7], [], time() + 60);
    $cache = $this->createStub(CacheInterface::class);
    $cache->method('set')->willReturn(false);
    expect(fn() => (new CacheSessionStore($cache))->save(str_repeat('b', 64), $payload))
        ->toThrow(RuntimeException::class, 'Unable to persist the browser session');
});

it('reports database session failures after a connection is lost', function (): void {
    $payload = new \Infocyph\Foundation\Session\SessionPayload(['account' => 7], [], time() + 60);
    $connection = new Connection(new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $connection->statement(
        'CREATE TABLE browser_sessions (id VARCHAR(64) PRIMARY KEY, payload TEXT NOT NULL, expires_at BIGINT NOT NULL)',
    );
    $database = new DatabaseSessionStore($connection, 'browser_sessions');
    $database->save(str_repeat('c', 64), $payload);
    $connection->disconnect();

    expect(fn() => $database->load(str_repeat('c', 64), time()))
        ->toThrow(QueryException::class);
});

it('releases CacheLayer session locks when request handling fails', function (): void {
    $config = SessionConfig::fromRepository(new ConfigRepository([
        'session' => [
            'driver' => 'array',
            'lock' => ['enabled' => true],
        ],
    ]), sys_get_temp_dir() . '/foundation-browser-sessions');
    $store = new ArraySessionStore();
    $id = str_repeat('f', 64);
    $store->save($id, new \Infocyph\Foundation\Session\SessionPayload(['value' => 1], [], time() + 60));
    $locks = new class implements LockProviderInterface {
        public int $acquired = 0;

        public bool $owned = true;

        public int $released = 0;

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            unset($waitSeconds);
            ++$this->acquired;

            return new LockHandle($key, 'token', leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $this->owned && $handle !== null && $leaseSeconds > 0;
        }

        public function release(?LockHandle $handle): void
        {
            if ($handle !== null) {
                ++$this->released;
            }
        }
    };
    $manager = new SessionManager(
        $config,
        static fn(): SessionStoreInterface => $store,
        static fn(): LockProviderInterface => $locks,
    );
    $middleware = new SessionMiddleware($manager, $config);

    expect(fn() => $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/fail')
            ->withCookieParams(['infbyte_session' => $id]),
        static function (Request $request): never {
            BrowserSession::fromRequest($request)->get('value');

            throw new RuntimeException('handler failed');
        },
    ))->toThrow(RuntimeException::class, 'handler failed')
        ->and($locks->acquired)->toBe(1)
        ->and($locks->released)->toBe(1);

    expect(fn() => $manager->current())->toThrow(LogicException::class);

    $locks->owned = false;
    expect(fn() => $middleware(
        Request::fake(headers: ['Host' => 'example.test'], uri: 'https://example.test/lost-lock')
            ->withCookieParams(['infbyte_session' => $id]),
        static function (Request $request): Response {
            BrowserSession::fromRequest($request)->put('value', 2);

            return Response::json(['ok' => true]);
        },
    ))->toThrow(RuntimeException::class, 'lock lease was lost')
        ->and($locks->acquired)->toBe(2)
        ->and($locks->released)->toBe(2);
});

it('isolates active sessions between concurrent fibers', function (): void {
    [, $manager] = browserSessionStack();
    $run = static function () use ($manager): bool {
        $session = $manager->open(null);
        $manager->enter($session);

        try {
            Fiber::suspend();

            return $manager->current() === $session;
        } finally {
            $manager->leave($session);
        }
    };
    $first = new Fiber($run);
    $second = new Fiber($run);

    $first->start();
    $second->start();
    $first->resume();
    $second->resume();

    expect($first->getReturn())->toBeTrue()
        ->and($second->getReturn())->toBeTrue()
        ->and(fn() => $manager->current())->toThrow(LogicException::class);
});

/**
 * @return array{SessionMiddleware, SessionManager, ArraySessionStore, SessionConfig}
 */
function browserSessionStack(): array
{
    $config = SessionConfig::fromRepository(new ConfigRepository([
        'session' => [
            'driver' => 'array',
            'cookie' => ['secure' => true],
        ],
    ]), sys_get_temp_dir() . '/foundation-browser-sessions');
    $store = new ArraySessionStore();
    $manager = new SessionManager(
        $config,
        static fn(): SessionStoreInterface => $store,
        static fn(): null => null,
    );

    return [new SessionMiddleware($manager, $config), $manager, $store, $config];
}

function browserSessionCookieId(Response $response): string
{
    preg_match('/(?:^|;\\s*)infbyte_session=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches);

    return $matches[1] ?? throw new RuntimeException('The response did not contain a session cookie.');
}

/**
 * @return array<string, mixed>
 */
function browserSessionJson(Response $response): array
{
    $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, string> $files
 */
function browserSessionProject(array $files): string
{
    $root = sys_get_temp_dir() . '/foundation-session-' . bin2hex(random_bytes(5));
    foreach ($files as $path => $contents) {
        $target = $root . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $contents);
    }

    return $root;
}

function browserSessionRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
