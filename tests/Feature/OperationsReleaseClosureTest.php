<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;

final class FoundationOperationsReleaseIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $json = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Choice input is not expected in this test IO.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void {}

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->json[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void {}

    public function password(string $question): string
    {
        throw new LogicException('Password input is not expected in this test IO.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Text input is not expected in this test IO.');
    }

    public function success(string $message): void {}

    public function table(array $headers, array $rows): void {}

    public function warning(string $message): void {}

    public function write(string $message): void {}

    public function writeln(string $message = ''): void {}
}

it('closes maintenance runtime control and process registry operations through real commands', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-operations-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/routes', 0775, true);

    $config = [
        'base_path' => $basePath,
        'app' => [
            'base_path' => $basePath,
            'env' => 'testing',
        ],
        'messaging' => [
            'workers' => [
                'jobs' => [
                    'transport' => 'memory',
                    'queue' => 'jobs',
                ],
            ],
        ],
        'operations' => [
            'runtime_registry' => [
                'path' => 'storage/framework/runtime',
                'visibility' => 'host',
                'stale_seconds' => 1,
            ],
            'runtime_control' => [
                'driver' => 'file',
                'path' => 'storage/framework/runtime-control.json',
            ],
            'maintenance' => [
                'driver' => 'file',
                'path' => 'storage/framework/maintenance.json',
            ],
        ],
    ];

    $dispatcher = CommandDispatcher::project(
        $config,
        manifestPath: $basePath . '/bootstrap/cache/commands.php',
        routesPath: $basePath . '/routes/console.php',
    );
    $registryApp = Foundation::cli($config);
    $registry = new RuntimeProcessRegistry($registryApp);
    $control = new RuntimeControl($registryApp);

    try {
        $initialMaintenance = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'maintenance:status']),
        );
        expect($initialMaintenance['enabled'] ?? null)->toBeFalse()
            ->and($initialMaintenance['driver'] ?? null)->toBe('file');

        $enabled = foundationOperationsPayload(foundationOperationsRun($dispatcher, [
            'infbyte',
            'maintenance:enable',
            '--retry=120',
            '--message=Deploying',
        ]));
        expect($enabled['enabled'] ?? null)->toBeTrue()
            ->and($enabled['retry_after'] ?? null)->toBe(120)
            ->and($enabled['message'] ?? null)->toBe('Deploying')
            ->and($enabled['driver'] ?? null)->toBe('file');

        $enabledStatus = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'maintenance:status']),
        );
        expect($enabledStatus['enabled'] ?? null)->toBeTrue()
            ->and($enabledStatus['retry_after'] ?? null)->toBe(120)
            ->and($enabledStatus['message'] ?? null)->toBe('Deploying');

        $disabled = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'maintenance:disable']),
        );
        $disabledAgain = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'maintenance:disable']),
        );
        expect($disabled)->toBe(['enabled' => false, 'changed' => true])
            ->and($disabledAgain)->toBe(['enabled' => false, 'changed' => false]);

        $runtimeBaseline = $control->token('runtime');
        $runtimeReload = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'runtime:reload']),
        );
        expect($runtimeReload['scope'] ?? null)->toBe('runtime')
            ->and($runtimeReload['token'] ?? null)->toBeString()->not->toBe($runtimeBaseline)
            ->and($control->token('runtime'))->toBe($runtimeReload['token']);

        $scheduleBaseline = $control->token('schedule');
        $scheduleInterrupt = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'schedule:interrupt']),
        );
        expect($scheduleInterrupt['scope'] ?? null)->toBe('schedule')
            ->and($scheduleInterrupt['token'] ?? null)->toBeString()->not->toBe($scheduleBaseline)
            ->and($control->token('schedule'))->toBe($scheduleInterrupt['token']);

        $workerBaseline = $control->token('worker');
        $allWorkerRestart = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'worker:restart']),
        );
        expect($allWorkerRestart['scope'] ?? null)->toBe('worker')
            ->and(array_key_exists('worker', $allWorkerRestart))->toBeTrue()
            ->and($allWorkerRestart['worker'])->toBeNull()
            ->and($allWorkerRestart['token'] ?? null)->toBeString()->not->toBe($workerBaseline)
            ->and($control->token('worker'))->toBe($allWorkerRestart['token']);

        $namedBaseline = $control->token('worker', 'jobs');
        $namedRestart = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'worker:restart', 'jobs']),
        );
        expect($namedRestart['scope'] ?? null)->toBe('worker')
            ->and($namedRestart['worker'] ?? null)->toBe('jobs')
            ->and($namedRestart['token'] ?? null)->toBeString()->not->toBe($namedBaseline)
            ->and($control->token('worker', 'jobs'))->toBe($namedRestart['token']);

        $record = $registry->register('worker', 'jobs');
        $activeStatus = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'worker:status', 'jobs']),
        );
        expect($activeStatus['worker'] ?? null)->toBe('jobs')
            ->and($activeStatus['registry_visibility'] ?? null)->toBe('host')
            ->and($activeStatus['configured']['jobs']['type'] ?? null)->toBe('messaging')
            ->and($activeStatus['processes'] ?? null)->toHaveCount(1)
            ->and($activeStatus['processes'][0]['id'] ?? null)->toBe($record['id'])
            ->and($activeStatus['processes'][0]['running'] ?? null)->toBeTrue();

        foundationOperationsMakeStale($basePath, $record);
        $staleStatus = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'worker:status', 'jobs']),
        );
        expect($staleStatus['processes'] ?? null)->toHaveCount(1)
            ->and($staleStatus['processes'][0]['id'] ?? null)->toBe($record['id'])
            ->and($staleStatus['processes'][0]['running'] ?? null)->toBeFalse();

        $registry->unregister($record);
        $cleanStatus = foundationOperationsPayload(
            foundationOperationsRun($dispatcher, ['infbyte', 'worker:status', 'jobs']),
        );
        expect($cleanStatus['processes'] ?? null)->toBe([])
            ->and($registry->all('worker', 'jobs'))->toBe([]);
    } finally {
        $registryApp->container()->unset();
        foundationOperationsRemove($basePath);
    }
});

/**
 * @param array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:true} $record
 */
function foundationOperationsMakeStale(string $basePath, array $record): void
{
    $path = $basePath . '/storage/framework/runtime/' . $record['id'] . '.json';
    $record['heartbeat_at'] = '2000-01-01T00:00:00+00:00';
    unset($record['running']);
    $payload = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to age the runtime registry fixture.');
    }
}

/** @return array<string, mixed> */
function foundationOperationsPayload(FoundationOperationsReleaseIO $io): array
{
    $payload = $io->json[0] ?? null;
    if (!is_array($payload)) {
        throw new RuntimeException('Operations command did not emit a machine-readable payload.');
    }

    return $payload;
}

/** @param list<string> $argv */
function foundationOperationsRun(CommandDispatcher $dispatcher, array $argv): FoundationOperationsReleaseIO
{
    $io = new FoundationOperationsReleaseIO();
    $exit = $dispatcher->run($argv, $io);
    if ($exit !== ExitCode::SUCCESS) {
        throw new RuntimeException(sprintf(
            '%s failed with exit %d: %s',
            $argv[1] ?? 'command',
            $exit,
            implode('; ', $io->errors),
        ));
    }

    return $io;
}

function foundationOperationsRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            foundationOperationsRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
