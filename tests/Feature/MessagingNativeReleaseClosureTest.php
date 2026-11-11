<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

final readonly class FoundationMessagingReleaseMessage
{
    public function __construct(public string $value) {}
}

final class FoundationMessagingCommandStateProvider extends ServiceProvider
{
    public static ?FailureStore $failures = null;

    public static ?TransportRegistry $transports = null;

    #[\Override]
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        // The selected test provider intentionally replaces the default module bindings.
        $builder->unbind(FailureStore::class);
        $builder->unbind(TransportRegistry::class);
        $builder->factory(
            FailureStore::class,
            static fn(): FailureStore => self::$failures
                ?? throw new LogicException('Omnibus command failure store fixture is not initialized.'),
        );
        $builder->factory(
            TransportRegistry::class,
            static fn(): TransportRegistry => self::$transports
                ?? throw new LogicException('Omnibus command transport registry fixture is not initialized.'),
        );
    }
}

final class FoundationMessagingRestartingHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public function __construct(private readonly Application $application) {}

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function __invoke(FoundationMessagingReleaseMessage $message): void
    {
        self::$handled[] = $message->value;
        if (count(self::$handled) === 1) {
            new RuntimeControl($this->application)->signal('worker', 'jobs');
        }
    }
}

final class FoundationMessagingCommandIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $json = [];

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Choice input is not expected in this test IO.');
    }

    #[\Override]
    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    #[\Override]
    public function info(string $message): void {}

    #[\Override]
    public function interactive(): bool
    {
        return false;
    }

    #[\Override]
    public function json(mixed $value): void
    {
        $this->json[] = $value;
    }

    #[\Override]
    public function machineReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function note(string $message): void {}

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function password(string $question): string
    {
        throw new LogicException('Password input is not expected in this test IO.');
    }

    #[\Override]
    public function quiet(): bool
    {
        return false;
    }

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Text input is not expected in this test IO.');
    }

    #[\Override]
    public function success(string $message): void {}

    #[\Override]
    public function table(array $headers, array $rows): void {}

    #[\Override]
    public function warning(string $message): void {}

    #[\Override]
    public function write(string $message): void {}

    #[\Override]
    public function writeln(string $message = ''): void {}
}

beforeEach(function (): void {
    FoundationMessagingCommandStateProvider::$failures = null;
    FoundationMessagingCommandStateProvider::$transports = null;
    FoundationMessagingRestartingHandler::$handled = [];
});

afterEach(function (): void {
    FoundationMessagingCommandStateProvider::$failures = null;
    FoundationMessagingCommandStateProvider::$transports = null;
});

it('executes Omnibus failure administration and queue monitoring through the real command dispatcher', function (): void {
    $project = foundationMessagingReleaseProject('commands');
    $clock = new SystemClock();
    $failures = new InMemoryFailureStore($clock);
    $transport = new InMemoryTransport($clock);
    FoundationMessagingCommandStateProvider::$failures = $failures;
    FoundationMessagingCommandStateProvider::$transports = new TransportRegistry(['shared' => $transport]);

    $failures->add(foundationMessagingFailure('retry-me', 'retry', '-5 minutes'));
    $failures->add(foundationMessagingFailure('old-one', 'old', '-3 days'));
    $failures->add(foundationMessagingFailure('forget-me', 'forget', 'now'));

    $dispatcher = CommandDispatcher::project([
        'base_path' => $project,
        'app' => ['base_path' => $project, 'env' => 'testing'],
        'providers' => ['common' => [FoundationMessagingCommandStateProvider::class]],
        'messaging' => ['consumer' => ['transport' => 'shared']],
    ], manifestPath: $project . '/bootstrap/cache/commands.php', routesPath: $project . '/routes/console.php');

    try {
        $listed = new FoundationMessagingCommandIO();
        $status = $dispatcher->run(['infbyte', 'queue:failed', '--limit=10'], $listed);
        expect($listed->errors)->toBe([])
            ->and($status)->toBe(ExitCode::SUCCESS)
            ->and($listed->json)->toHaveCount(1)
            ->and($listed->json[0])->toHaveCount(3);

        $shown = new FoundationMessagingCommandIO();
        expect($dispatcher->run(['infbyte', 'queue:failed:show', 'retry-me'], $shown))->toBe(ExitCode::SUCCESS);
        if (!isset($shown->json[0]) || !is_array($shown->json[0])) {
            throw new \UnexpectedValueException('Failed-message detail must be an array.');
        }
        expect($shown->json[0]['id'] ?? null)->toBe('retry-me')
            ->and($shown->json[0]['decoded'] ?? null)->toBeTrue();

        $retried = new FoundationMessagingCommandIO();
        expect($dispatcher->run([
            'infbyte',
            'queue:retry',
            'retry-me',
            '--transport=shared',
            '--queue=retried',
        ], $retried))->toBe(ExitCode::SUCCESS)
            ->and($failures->find('retry-me'))->toBeNull()
            ->and($transport->size('retried'))->toBe(1)
            ->and($retried->json[0]['queue'] ?? null)->toBe('retried');

        $monitored = new FoundationMessagingCommandIO();
        expect($dispatcher->run([
            'infbyte',
            'queue:monitor',
            '--transport=shared',
            '--queue=retried',
        ], $monitored))->toBe(ExitCode::SUCCESS)
            ->and($monitored->json[0] ?? null)->toBe([
                'transport' => 'shared',
                'queue' => 'retried',
                'size' => 1,
            ]);

        $pruned = new FoundationMessagingCommandIO();
        expect($dispatcher->run(['infbyte', 'queue:prune-failed', '--hours=24'], $pruned))->toBe(ExitCode::SUCCESS)
            ->and($failures->find('old-one'))->toBeNull()
            ->and($pruned->json[0]['pruned'] ?? null)->toBe(1);

        $forgotten = new FoundationMessagingCommandIO();
        expect($dispatcher->run(['infbyte', 'queue:forget', 'forget-me'], $forgotten))->toBe(ExitCode::SUCCESS)
            ->and($failures->find('forget-me'))->toBeNull();

        $failures->add(foundationMessagingFailure('flush-a', 'a', 'now'));
        $failures->add(foundationMessagingFailure('flush-b', 'b', 'now'));
        $denied = new FoundationMessagingCommandIO();
        expect($dispatcher->run(['infbyte', 'queue:flush', '--no-interaction'], $denied))->toBe(ExitCode::FAILURE)
            ->and($failures->all())->toHaveCount(2)
            ->and($denied->errors)->toContain(
                'This destructive queue operation requires --force in non-interactive mode.',
            );

        $flushed = new FoundationMessagingCommandIO();
        expect($dispatcher->run(['infbyte', 'queue:flush', '--no-interaction', '--force'], $flushed))
            ->toBe(ExitCode::SUCCESS)
            ->and($failures->all())->toBe([])
            ->and($flushed->json[0]['flushed'] ?? null)->toBe(2);
    } finally {
        foundationMessagingReleaseRemove($project);
    }
});

it('gracefully stops an Omnibus message worker after a named restart signal and leaves later work queued', function (): void {
    $project = foundationMessagingReleaseProject('restart');
    $app = Foundation::worker([
        'app' => ['base_path' => $project, 'env' => 'testing'],
        'operations' => [
            'runtime_control' => ['path' => 'storage/framework/runtime-control.json'],
            'runtime_registry' => ['path' => 'storage/framework/runtime', 'stale_seconds' => 15],
        ],
        'messaging' => [
            'handlers' => [FoundationMessagingReleaseMessage::class => FoundationMessagingRestartingHandler::class],
            'routes' => [
                FoundationMessagingReleaseMessage::class => ['transport' => 'memory', 'queue' => 'jobs'],
            ],
            'consumer' => ['transport' => 'memory'],
            'retry' => [
                'maximum_attempts' => 1,
                'initial_delay_seconds' => 0.0,
                'multiplier' => 1.0,
                'maximum_delay_seconds' => 0.0,
                'jitter_ratio' => 0.0,
            ],
            'workers' => [
                'jobs' => [
                    'transport' => 'memory',
                    'queue' => 'jobs',
                    'prefetch' => 1,
                    'visibility_seconds' => 60.0,
                    'idle_sleep_seconds' => 0.0,
                    'max_idle_sleep_seconds' => 0.0,
                    'idle_jitter_ratio' => 0.0,
                    'max_messages' => 10,
                    'handle_signals' => false,
                    'pool' => ['enabled' => false],
                ],
            ],
        ],
    ]);

    try {
        $bus = $app->make(MessageBus::class);
        $bus->dispatch(new FoundationMessagingReleaseMessage('first'));
        $bus->dispatch(new FoundationMessagingReleaseMessage('second'));

        expect(new WorkerManager($app)->run('jobs'))->toBe(0)
            ->and(FoundationMessagingRestartingHandler::$handled)->toBe(['first'])
            ->and(new RuntimeProcessRegistry($app)->all('worker', 'jobs'))->toBe([]);

        $transport = $app->make(TransportRegistry::class)->get('memory');
        expect($transport)->toBeInstanceOf(InMemoryTransport::class);
        if (!$transport instanceof InMemoryTransport) {
            throw new LogicException('Expected the Omnibus in-memory transport.');
        }
        expect($transport->size('jobs'))->toBe(1);
    } finally {
        foundationMessagingReleaseRemove($project);
    }
});

function foundationMessagingFailure(string $id, string $value, string $failedAt): FailedMessage
{
    return FailedMessage::decoded(
        $id,
        'failed',
        Envelope::wrap(new FoundationMessagingReleaseMessage($value)),
        1,
        new DateTimeImmutable($failedAt),
        RuntimeException::class,
        'expected release closure failure',
    );
}

function foundationMessagingReleaseProject(string $name): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-omnibus-26-' . $name . '-' . bin2hex(random_bytes(5));
    mkdir($project . '/storage/framework', 0777, true);

    return $project;
}

/** @throws \UnexpectedValueException */
function foundationMessagingReleaseRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Expected a filesystem iterator entry.');
        }

        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
