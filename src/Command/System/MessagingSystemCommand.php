<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Transport\Receiver;
use Infocyph\Omnibus\Transport\TransportRegistry;

final class MessagingSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'messaging:list' => $this->messagingList(),
            'queue:failed' => $this->failed(),
            'queue:failed:show' => $this->failedShow(),
            'queue:flush' => $this->flush(),
            'queue:forget' => $this->forget(),
            'queue:monitor' => $this->monitor(),
            'queue:prune-failed' => $this->pruneFailed(),
            'queue:retry' => $this->retry(),
            default => throw new \LogicException('Unsupported messaging system command.'),
        };
    }

    private function failed(): int
    {
        $failures = $this->failures()->all($this->positiveIntOption('limit', 100, 1_000));
        $data = array_map($this->failureData(...), $failures);
        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }
        if ($data === []) {
            $this->io()->info('No failed messages.');

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['ID', 'Queue', 'Attempt', 'Failed At', 'Failure', 'Reason'],
            array_map(static fn(array $failure): array => [
                $failure['id'],
                $failure['queue'],
                $failure['attempt'],
                $failure['failed_at'],
                $failure['failure_class'],
                $failure['reason'],
            ], $data),
        );

        return ExitCode::SUCCESS;
    }

    private function failedShow(): int
    {
        $id = $this->argument(0) ?? throw new \LogicException('Validated failed-message id is unavailable.');
        $failure = $this->failures()->find($id);
        if (!$failure instanceof FailedMessage) {
            $this->io()->error(sprintf('Failed message "%s" was not found.', $id));

            return ExitCode::FAILURE;
        }

        return $this->emit($this->failureData($failure));
    }

    /** @return array<string,mixed> */
    private function failureData(FailedMessage $failure): array
    {
        return [
            'id' => $failure->id,
            'queue' => $failure->queue,
            'attempt' => $failure->attempt,
            'failed_at' => $failure->failedAt->format(DATE_ATOM),
            'failure_class' => $failure->failureClass,
            'reason' => $failure->reason,
            'decoded' => $failure->envelope !== null,
            'payload_truncated' => $failure->payloadTruncated,
            'message' => $failure->envelope?->message::class,
        ];
    }

    private function failures(): FailureStore
    {
        return $this->application->make(FailureStore::class);
    }

    private function flush(): int
    {
        if (!$this->authorize('Flush every failed message?')) {
            return ExitCode::FAILURE;
        }

        $count = $this->manager()->flush();

        return $this->emit(['flushed' => $count], sprintf('Flushed %d failed message(s).', $count));
    }

    private function forget(): int
    {
        $id = $this->argument(0) ?? throw new \LogicException('Validated failed-message id is unavailable.');
        $removed = $this->manager()->forget($id);
        if (!$removed) {
            $this->io()->error(sprintf('Failed message "%s" was not found.', $id));

            return ExitCode::FAILURE;
        }

        return $this->emit(['id' => $id, 'removed' => true], sprintf('Forgot failed message "%s".', $id));
    }

    private function manager(): FailureManager
    {
        return new FailureManager($this->failures());
    }

    private function messagingList(): int
    {
        $config = $this->application->config();
        $data = [
            'default_route' => $config->get('messaging.default_route', []),
            'consumer_transport' => $config->get('messaging.consumer.transport', 'memory'),
            'routes' => $this->map($config->get('messaging.routes', [])),
            'handlers' => $this->map($config->get('messaging.handlers', [])),
            'listeners' => $this->map($config->get('messaging.listeners', [])),
            'scheduled_messages' => $this->map($config->get('messaging.scheduled_messages', [])),
            'workers' => $this->map($config->get('messaging.workers', [])),
            'failure_store' => $this->failures()::class,
        ];
        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }

        $this->io()->table(
            ['Surface', 'Count / Value'],
            [
                ['Routes', count($data['routes'])],
                ['Handlers', count($data['handlers'])],
                ['Listener groups', count($data['listeners'])],
                ['Scheduled messages', count($data['scheduled_messages'])],
                ['Workers', count($data['workers'])],
                ['Consumer transport', (string) $data['consumer_transport']],
                ['Failure store', $data['failure_store']],
            ],
        );

        return ExitCode::SUCCESS;
    }

    private function monitor(): int
    {
        $transport = $this->transport();
        if (!$transport instanceof Receiver) {
            throw new \LogicException(sprintf(
                'Messaging transport "%s" does not support receiving/queue-size inspection.',
                $this->transportName(),
            ));
        }
        $queue = $this->option('queue', 'default') ?? 'default';
        $size = $transport->size($queue);

        return $this->emit(
            ['transport' => $this->transportName(), 'queue' => $queue, 'size' => $size],
            sprintf('%s/%s has %d queued message(s).', $this->transportName(), $queue, $size),
        );
    }

    private function pruneFailed(): int
    {
        $hours = $this->positiveIntOption('hours', 168, 24 * 365 * 10);
        $before = new \DateTimeImmutable(sprintf('-%d hours', $hours));
        $count = $this->manager()->prune($before);

        return $this->emit([
            'pruned' => $count,
            'before' => $before->format(DATE_ATOM),
        ], sprintf('Pruned %d failed message(s).', $count));
    }

    private function retry(): int
    {
        $id = $this->argument(0) ?? throw new \LogicException('Validated failed-message id is unavailable.');
        $queue = $this->option('queue');
        $sent = $this->manager()->retry($id, $this->transport(), $queue);

        return $this->emit([
            'id' => $id,
            'transport' => $this->transportName(),
            'queue' => $queue,
            'message' => $sent->message::class,
        ], sprintf('Retried failed message "%s".', $id));
    }

    private function transport(): \Infocyph\Omnibus\Transport\Sender
    {
        return $this->application->make(TransportRegistry::class)->get($this->transportName());
    }

    private function transportName(): string
    {
        return $this->option('transport')
            ?? (is_string($configured = $this->application->config()->get('messaging.consumer.transport')) && $configured !== ''
                ? $configured
                : 'memory');
    }

    private function authorize(string $question): bool
    {
        if ($this->flag('force')) {
            return true;
        }
        if (!$this->io()->interactive()) {
            $this->io()->error('This destructive queue operation requires --force in non-interactive mode.');

            return false;
        }

        return $this->io()->confirm($question, false);
    }

    /** @return array<array-key,mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function positiveIntOption(string $name, int $default, int $maximum): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1 || (int) $value > $maximum) {
            throw new \InvalidArgumentException(sprintf('--%s must be between 1 and %d.', $name, $maximum));
        }

        return (int) $value;
    }
}
