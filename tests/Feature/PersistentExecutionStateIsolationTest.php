<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Consumer\ExecutionScope as OmnibusExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final readonly class FoundationPersistentPrincipal implements PrincipalInterface
{
    public function __construct(private string $identifier) {}

    public function accountId(): ?string
    {
        return $this->identifier;
    }

    public function id(): string
    {
        return $this->identifier;
    }

    public function metadata(): array
    {
        return [];
    }

    public function type(): PrincipalType
    {
        return PrincipalType::ACCOUNT;
    }
}

final class FoundationPersistentScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationPersistentStateProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->bind(
            'persistent.execution.scoped',
            static fn(): FoundationPersistentScopedProbe => new FoundationPersistentScopedProbe(),
            LifetimeEnum::Scoped,
        );
    }
}

final readonly class FoundationPersistentMessage
{
    public function __construct(public string $value) {}
}

it('cleans all request-local state between persistent execution units including failure paths', function (): void {
    DB::purge();
    Memoizer::instance()->flush();
    OnceMemoizer::instance()->flush();
    $project = foundationPersistentStateProject();
    $memoCalls = ['memo' => 0, 'once' => 0];

    try {
        $app = Foundation::web([
            'app' => [
                'base_path' => $project,
                'env' => 'testing',
            ],
            'providers' => [
                'common' => [FoundationPersistentStateProvider::class],
            ],
            'session' => [
                'driver' => 'array',
                'lock' => ['enabled' => false],
            ],
            'database' => [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'driver' => 'sqlite',
                        'database' => 'database/persistent-state.sqlite',
                    ],
                ],
            ],
        ])->boot();

        $principal = $app->make(CurrentPrincipalContext::class);
        $sessions = $app->make(SessionManager::class);
        $databaseFactory = $app->make(DBLayerFactory::class);
        $database = $databaseFactory->connection();
        $database->getPdo()->exec('CREATE TABLE runtime_state (value TEXT NOT NULL)');

        $firstScoped = null;
        expect(fn() => $app->execution()->run(function (ExecutionId $executionId) use (
            $app,
            $principal,
            $sessions,
            $databaseFactory,
            &$memoCalls,
            &$firstScoped,
        ): void {
            expect((string) $executionId)->not->toBe('');

            $first = $app->make('persistent.execution.scoped');
            $same = $app->make('persistent.execution.scoped');
            expect($first)->toBe($same);
            $firstScoped = $first->sequence;

            $principal->set(new FoundationPersistentPrincipal('account-one'));
            expect($principal->get()?->id())->toBe('account-one');

            $browser = $sessions->open(null);
            $sessions->enter($browser);
            $browser->put('request_only', 'first');
            expect($sessions->current())->toBe($browser);

            $connection = $databaseFactory->connection();
            $connection->beginTransaction();
            $connection->insert('INSERT INTO runtime_state (value) VALUES (?)', ['uncommitted']);
            expect($connection->transactionLevel())->toBe(1);

            expect(foundationPersistentMemoized($memoCalls))->toBe(1)
                ->and(foundationPersistentOnce($memoCalls))->toBe(1);

            throw new RuntimeException('deliberate persistent execution failure');
        }))->toThrow(RuntimeException::class, 'deliberate persistent execution failure');

        expect($principal->get())->toBeNull()
            ->and(fn() => $sessions->current())->toThrow(LogicException::class)
            ->and($database->transactionLevel())->toBe(0)
            ->and((int) $database->getPdo()->query('SELECT COUNT(*) FROM runtime_state')->fetchColumn())->toBe(0)
            ->and(Memoizer::instance()->stats())->toBe(['hits' => 0, 'misses' => 0, 'total' => 0]);

        $secondScoped = null;
        $app->execution()->run(function () use (
            $app,
            $principal,
            $sessions,
            $databaseFactory,
            &$memoCalls,
            &$secondScoped,
        ): void {
            expect($principal->get())->toBeNull()
                ->and(fn() => $sessions->current())->toThrow(LogicException::class);

            $secondScoped = $app->make('persistent.execution.scoped')->sequence;
            $connection = $databaseFactory->connection();
            expect($connection->transactionLevel())->toBe(0)
                ->and(foundationPersistentMemoized($memoCalls))->toBe(2)
                ->and(foundationPersistentOnce($memoCalls))->toBe(2);
        });

        expect($firstScoped)->toBeInt()
            ->and($secondScoped)->toBeInt()
            ->and($secondScoped)->not->toBe($firstScoped)
            ->and(Memoizer::instance()->stats())->toBe(['hits' => 0, 'misses' => 0, 'total' => 0]);

        $messagingScope = $app->make(OmnibusExecutionScope::class);
        $seen = [];
        foreach (['one', 'two'] as $value) {
            $message = new FoundationPersistentMessage($value);
            $envelope = new Envelope($message, [new MessageIdStamp('persistent-' . $value)]);
            $messagingScope->run($envelope, function (object $current, Envelope $delivery) use ($app, $value, &$seen): void {
                $seededEnvelope = $app->make(Envelope::class);
                $seededMessage = $app->make(FoundationPersistentMessage::class);
                $executionId = $app->make(ExecutionId::class);
                $scoped = $app->make('persistent.execution.scoped');

                expect($current)->toBe($seededMessage)
                    ->and($delivery)->toBe($seededEnvelope)
                    ->and($seededMessage->value)->toBe($value)
                    ->and((string) $executionId)->toBe('omnibus:persistent-' . $value);

                $seen[] = [
                    'value' => $seededMessage->value,
                    'envelope' => spl_object_id($seededEnvelope),
                    'scoped' => $scoped->sequence,
                ];
            });
        }

        expect($seen)->toHaveCount(2)
            ->and(array_column($seen, 'value'))->toBe(['one', 'two'])
            ->and($seen[0]['envelope'])->not->toBe($seen[1]['envelope'])
            ->and($seen[0]['scoped'])->not->toBe($seen[1]['scoped']);
    } finally {
        DB::purge();
        Memoizer::instance()->flush();
        OnceMemoizer::instance()->flush();
        foundationPersistentStateRemove($project);
    }
});

/** @param array{memo:int,once:int} $calls */
function foundationPersistentMemoized(array &$calls): int
{
    return Memoizer::instance()->get(static function () use (&$calls): int {
        return ++$calls['memo'];
    });
}

/** @param array{memo:int,once:int} $calls */
function foundationPersistentOnce(array &$calls): int
{
    return OnceMemoizer::instance()->once(static function () use (&$calls): int {
        return ++$calls['once'];
    });
}

function foundationPersistentStateProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-persistent-state-' . bin2hex(random_bytes(5));
    mkdir($project . '/database', 0777, true);
    mkdir($project . '/storage/framework/sessions', 0777, true);

    return $project;
}

function foundationPersistentStateRemove(string $directory): void
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
