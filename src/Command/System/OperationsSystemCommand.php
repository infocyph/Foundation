<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\AuthPruner;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Config\ProductionSecurityValidator;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Logging\LogTailer;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Security\EnvironmentFileProtector;
use Infocyph\Foundation\Worker\WorkerManager;

final class OperationsSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'auth:prune' => $this->authPrune(),
            'config:validate' => $this->configValidate(),
            'env:decrypt' => $this->environment(false),
            'env:encrypt' => $this->environment(true),
            'execution:clear' => $this->executionClear(),
            'execution:list' => $this->executionList(),
            'execution:show' => $this->executionShow(),
            'log:tail' => $this->logTail(),
            'maintenance:disable' => $this->maintenanceDisable(),
            'maintenance:enable' => $this->maintenanceEnable(),
            'maintenance:status' => $this->maintenanceStatus(),
            'runtime:reload' => $this->runtimeReload(),
            'schedule:interrupt' => $this->scheduleInterrupt(),
            'worker:restart' => $this->workerRestart(),
            'worker:status' => $this->workerStatus(),
            default => throw new \LogicException('Unsupported operations system command.'),
        };
    }

    /**
     * @param array<string,mixed> $record
     * @return list<bool|float|int|string|null>
     */
    private static function executionRow(array $record): array
    {
        $recordedAt = $record['recorded_at'] ?? null;

        return [
            is_int($recordedAt) || is_float($recordedAt) ? gmdate(DATE_ATOM, (int) $recordedAt) : '',
            self::tableValue($record['kind'] ?? null),
            self::tableValue($record['execution_id'] ?? null),
            self::tableValue($record['name'] ?? null),
            self::tableValue($record['status'] ?? null),
            self::tableValue($record['exit_code'] ?? null),
        ];
    }

    private static function tableValue(mixed $value): bool|float|int|string|null
    {
        return $value === null || is_scalar($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function authorize(string $question): bool
    {
        if ($this->flag('force')) {
            return true;
        }
        if (!$this->io()->interactive()) {
            $this->io()->error('This destructive operation requires --force in non-interactive mode.');

            return false;
        }

        return $this->io()->confirm($question, false);
    }

    private function authPrune(): int
    {
        $retention = $this->nonNegativeIntOption('retention-hours', 24);
        $pruner = new AuthPruner(
            $this->application->make(DBLayerFactory::class),
            $this->application->make(AuthTables::class),
            $this->application->make(AuthSchemaInstaller::class),
        );
        $counts = $pruner->prune($this->option('connection'), $retention);
        $total = array_sum($counts);

        return $this->emit(
            ['pruned' => $counts, 'total' => $total, 'retention_hours' => $retention],
            sprintf('Pruned %d expired/revoked authentication record(s).', $total),
        );
    }

    private function configValidate(): int
    {
        $production = $this->flag('production') || $this->application->isProduction();
        $validator = new ConfigValidator($this->application->config());
        $result = $production ? $validator->validateForProduction() : $validator->validate();
        $issues = $result->issues();

        if ($this->application->config()->get('auth.drivers.mfa', 'simple') === 'otp') {
            $issues = [
                ...$issues,
                ...new OtpConfigValidator($this->application->config())->validate($production),
            ];
        }
        if ($production) {
            $issues = [...$issues, ...new ProductionSecurityValidator($this->application->config())->validate()];
        }

        $data = [
            'valid' => $issues === [],
            'production' => $production,
            'issues' => array_map(static fn($issue): array => [
                'message' => $issue->message,
                'key' => $issue->key,
                'severity' => $issue->severity,
            ], $issues),
        ];
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } elseif ($data['valid']) {
            $this->io()->success($production
                ? 'Configuration is valid for production.'
                : 'Configuration is valid.');
        } else {
            $this->io()->table(
                ['Severity', 'Key', 'Message'],
                array_map(
                    static fn(array $issue): array => [$issue['severity'], $issue['key'], $issue['message']],
                    $data['issues'],
                ),
            );
        }

        return $data['valid'] ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function control(): RuntimeControl
    {
        return new RuntimeControl($this->application);
    }

    private function environment(bool $encrypt): int
    {
        $protector = new EnvironmentFileProtector($this->application);
        $arguments = [
            $this->option('input'),
            $this->option('output'),
            $this->option('key-file'),
            $this->option('key-env', 'ENV_ENCRYPTION_KEY') ?? 'ENV_ENCRYPTION_KEY',
            $this->flag('force'),
        ];
        $path = $encrypt ? $protector->encrypt(...$arguments) : $protector->decrypt(...$arguments);

        return $this->emit(
            ['path' => $path, 'operation' => $encrypt ? 'encrypt' : 'decrypt'],
            sprintf('Environment file %s completed: %s', $encrypt ? 'encryption' : 'decryption', $path),
        );
    }

    private function executionClear(): int
    {
        if (!$this->authorize('Clear all execution history?')) {
            return ExitCode::FAILURE;
        }
        $removed = $this->history()->clear();

        return $this->emit(['removed' => $removed], $removed ? 'Execution history cleared.' : 'Execution history is already empty.');
    }

    private function executionList(): int
    {
        $records = $this->history()->recent(
            $this->positiveIntOption('limit', 100, 1_000),
            $this->option('kind'),
            $this->option('name'),
        );
        if ($this->io()->machineReadable()) {
            return $this->emit($records);
        }
        if ($records === []) {
            $this->io()->info('No execution history records.');

            return ExitCode::SUCCESS;
        }
        $this->io()->table(
            ['Recorded', 'Kind', 'Execution ID', 'Name', 'Status', 'Exit'],
            array_map(self::executionRow(...), $records),
        );

        return ExitCode::SUCCESS;
    }

    private function executionShow(): int
    {
        $id = $this->argument(0) ?? throw new \LogicException('Validated execution id is unavailable.');
        $records = $this->history()->find($id);
        if ($records === []) {
            $this->io()->error(sprintf('Execution "%s" was not found.', $id));

            return ExitCode::FAILURE;
        }

        return $this->emit(['execution_id' => $id, 'records' => $records]);
    }

    private function history(): ExecutionHistory
    {
        return new ExecutionHistory($this->application);
    }

    private function logTail(): int
    {
        if ($this->application->config()->get('logging.driver', 'null') !== 'file') {
            throw new \LogicException('log:tail requires the built-in logging.driver=file backend.');
        }
        $configured = $this->application->config()->get('logging.path');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : 'storage/logs/foundation.log';
        if (preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) !== 1) {
            $path = $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
        }
        $tailer = new LogTailer();
        foreach ($tailer->tail($path, $this->positiveIntOption('lines', 100, 10_000)) as $line) {
            $this->io()->writeln($line);
        }
        if ($this->flag('follow')) {
            $tailer->follow($path, function (string $line): void {
                $this->io()->writeln($line);
            });
        }

        return ExitCode::SUCCESS;
    }

    private function maintenance(): MaintenanceManager
    {
        return new MaintenanceManager($this->application);
    }

    private function maintenanceDisable(): int
    {
        $removed = $this->maintenance()->disable();

        return $this->emit(
            ['enabled' => false, 'changed' => $removed],
            $removed ? 'Maintenance mode disabled.' : 'Maintenance mode is already disabled.',
        );
    }

    private function maintenanceEnable(): int
    {
        $retry = $this->nullablePositiveIntOption('retry');
        $state = $this->maintenance()->enable($retry, $this->option('message'));

        return $this->emit($state, 'Maintenance mode enabled.');
    }

    private function maintenanceStatus(): int
    {
        $state = $this->maintenance()->status();
        if ($this->io()->machineReadable()) {
            return $this->emit($state);
        }
        $this->io()->table(
            ['Enabled', 'Driver', 'Enabled At', 'Retry After', 'Message'],
            [[
                $state['enabled'],
                $state['driver'],
                $state['enabled_at'] ?? '',
                $state['retry_after'] ?? '',
                $state['message'] ?? '',
            ]],
        );

        return ExitCode::SUCCESS;
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^\d+$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a non-negative integer.', $name));
        }

        return (int) $value;
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null ? null : $this->positiveInt($name, $value);
    }

    private function positiveInt(string $name, string $value): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function positiveIntOption(string $name, int $default, int $maximum): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        $resolved = $this->positiveInt($name, $value);
        if ($resolved > $maximum) {
            throw new \InvalidArgumentException(sprintf('--%s must not exceed %d.', $name, $maximum));
        }

        return $resolved;
    }

    private function runtimeReload(): int
    {
        $token = $this->control()->signal('runtime');

        return $this->emit(['scope' => 'runtime', 'token' => $token], 'Persistent runtime reload requested.');
    }

    private function scheduleInterrupt(): int
    {
        $token = $this->control()->signal('schedule');

        return $this->emit(['scope' => 'schedule', 'token' => $token], 'Scheduler interrupt requested.');
    }

    private function workerRestart(): int
    {
        $name = $this->argument(0);
        if ($name !== null && !array_key_exists($name, new WorkerManager($this->application)->all())) {
            throw new \InvalidArgumentException(sprintf('Worker "%s" is not configured.', $name));
        }
        $token = $this->control()->signal('worker', $name);

        return $this->emit(
            ['scope' => 'worker', 'worker' => $name, 'token' => $token],
            $name === null ? 'All worker restarts requested.' : sprintf('Worker "%s" restart requested.', $name),
        );
    }

    private function workerStatus(): int
    {
        $name = $this->argument(0);
        $configured = new WorkerManager($this->application)->all();
        if ($name !== null && !isset($configured[$name])) {
            throw new \InvalidArgumentException(sprintf('Worker "%s" is not configured.', $name));
        }
        $registry = new RuntimeProcessRegistry($this->application);
        $processes = $registry->all('worker', $name);
        $data = [
            'worker' => $name,
            'configured' => $name === null ? $configured : [$name => $configured[$name]],
            'registry_visibility' => $registry->visibility(),
            'processes' => $processes,
            'control' => $this->control()->status(),
        ];
        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }
        if ($processes === []) {
            $this->io()->note(sprintf(
                'No registered worker process is visible in the %s runtime registry.',
                $data['registry_visibility'],
            ));

            return ExitCode::SUCCESS;
        }
        $this->io()->table(
            ['Worker', 'PID', 'Host', 'Started', 'Heartbeat', 'Running'],
            array_map(static fn(array $process): array => [
                $process['name'],
                $process['pid'],
                $process['host'],
                $process['started_at'],
                $process['heartbeat_at'],
                $process['running'],
            ], $processes),
        );

        return ExitCode::SUCCESS;
    }
}
