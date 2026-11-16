<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDescriptor;
use Infocyph\Foundation\Command\CommandExecutionCoordinator;
use Infocyph\Foundation\Command\CommandExecutionPolicy;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Command\OverlapMode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Tests\Fixtures\NoopCommandIO;

it('keeps primary command error ahead of lock release and reports otherwise unmasked cleanup errors', function (): void {
    $root = sys_get_temp_dir() . '/foundation-command-lock-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    $entry = $root . '/child.php';
    file_put_contents($entry, "<?php\ndeclare(strict_types=1);\nexit(0);\n");
    $app = Foundation::cli(['app' => ['base_path' => $root], '_config_cache' => false]);
    $definition = (new CommandDefinition('test:cleanup'))->execution(
        new CommandExecutionPolicy(isolated: true, overlap: OverlapMode::Skip),
    );
    $descriptor = new CommandDescriptor($definition);
    $io = new NoopCommandIO();
    $lock = new class implements LockProviderInterface {
        public int $releases = 0;
        public bool $grant = true;
        public bool $failRelease = true;

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            unset($waitSeconds);

            return $this->grant ? new LockHandle($key, 'test-lock', leaseSeconds: $leaseSeconds) : null;
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            unset($handle, $leaseSeconds);

            return true;
        }

        /**
         * @throws \RuntimeException
         */
        #[\Override]
        public function release(?LockHandle $handle): void
        {
            expect($handle)->not->toBeNull();
            $this->releases++;
            if ($this->failRelease) {
                throw new RuntimeException('release-failed');
            }
        }
    };

    try {
        $withLock = static function (string $executable) use ($app, $lock): CommandExecutionCoordinator {
            $coordinator = new CommandExecutionCoordinator($app, executable: $executable);
            new ReflectionProperty($coordinator, 'locks')->setValue($coordinator, $lock);

            return $coordinator;
        };
        expect(fn() => $withLock($root . '/absent.php')->run($descriptor, ['infbyte'], static fn(): int => 0, $io))
            ->toThrow(LogicException::class, 'requires an executable application entry file');
        expect($lock->releases)->toBe(1);

        expect(fn() => $withLock($entry)->run($descriptor, ['infbyte'], static fn(): int => 0, $io))
            ->toThrow(RuntimeException::class, 'release-failed');
        expect($lock->releases)->toBe(2);

        $lock->grant = false;
        expect($withLock($entry)->run($descriptor, ['infbyte'], static fn(): int => 0, $io))
            ->toBe(ExitCode::SUCCESS);
        expect($lock->releases)->toBe(2);
    } finally {
        unlink($entry);
        rmdir($root);
    }
});

it('preserves the original inline command failure when secondary history persistence fails', function (): void {
    $root = sys_get_temp_dir() . '/foundation-command-history-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    $path = $root . '/history.jsonl';
    $app = Foundation::cli([
        'app' => ['base_path' => $root],
        '_config_cache' => false,
        'operations' => ['history' => ['enabled' => true, 'path' => $path]],
    ]);
    $descriptor = new CommandDescriptor(new CommandDefinition('test:history'));
    $coordinator = new CommandExecutionCoordinator($app);
    $io = new NoopCommandIO();

    try {
        // Convert a deliberately broken history destination to a real
        // exception rather than leaking an expected fopen warning into Pest.
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        expect(fn() => $coordinator->run(
            $descriptor,
            ['infbyte'],
            static function () use ($path): int {
                // Pending and Running history entries were written successfully.
                // Make only the failure-recording destination invalid.
                if (!unlink($path) || !mkdir($path, 0700)) {
                    throw new RuntimeException('Could not create the failing history fixture.');
                }

                throw new LogicException('authoritative-command-failure');
            },
            $io,
        ))->toThrow(LogicException::class, 'authoritative-command-failure');
        expect(is_dir($path))->toBeTrue();
    } finally {
        restore_error_handler();
        if (is_dir($path)) {
            rmdir($path);
        }
        if (is_file($path . '.lock')) {
            unlink($path . '.lock');
        }
        rmdir($root);
    }
});
