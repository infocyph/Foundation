<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class ProgressIndicator
{
    private const array SPINNER = ['|', '/', '-', '\\'];

    public function __construct(private CommandIO $io) {}

    /**
     * Iterate work with a progress bar when total is known or spinner-style
     * feedback when it is not. The callback receives ($item, $zeroBasedIndex).
     *
     * @param iterable<mixed> $items
     */
    public function iterate(
        iterable $items,
        callable $handler,
        ?int $total = null,
        string $label = 'Working',
    ): int {
        $this->assertTotal($total);
        $render = $this->renderable();
        if ($render) {
            $this->render($label, 0, $total);
        }

        try {
            $count = $this->consume($items, $handler, $total, $label, $render);
        } catch (\Throwable $exception) {
            $this->reportFailure($label, $render, $exception);

            throw $exception;
        }

        $this->assertProducedTotal($count, $total, $render);
        $this->finish($label, $count, $render);

        return $count;
    }

    /**
     * @template T
     * @param callable():T $task
     * @return T
     */
    public function task(string $label, callable $task): mixed
    {
        if ($label === '') {
            throw new \InvalidArgumentException('Task label cannot be empty.');
        }
        if (!$this->io->quiet() && !$this->io->machineReadable()) {
            $this->io->note($label . '...');
        }

        try {
            $result = $task();
        } catch (\Throwable $exception) {
            $this->io->error(sprintf('%s failed: %s', $label, $exception->getMessage()));

            throw $exception;
        }

        if (!$this->io->quiet() && !$this->io->machineReadable()) {
            $this->io->success($label . ' completed.');
        }

        return $result;
    }

    private function assertProducedTotal(int $count, ?int $total, bool $render): void
    {
        if ($total === null || $count === $total) {
            return;
        }
        if ($render) {
            $this->io->writeln();
        }

        throw new \UnexpectedValueException(sprintf(
            'Progress iterable produced %d item(s), expected %d.',
            $count,
            $total,
        ));
    }

    private function assertTotal(?int $total): void
    {
        if ($total !== null && $total < 0) {
            throw new \InvalidArgumentException('Progress total cannot be negative.');
        }
    }

    /** @param iterable<mixed> $items */
    private function consume(
        iterable $items,
        callable $handler,
        ?int $total,
        string $label,
        bool $render,
    ): int {
        $count = 0;
        foreach ($items as $item) {
            $handler($item, $count);
            $count++;
            if ($total !== null && $count > $total) {
                throw new \UnexpectedValueException('Progress iterable exceeded its declared total.');
            }
            if ($render) {
                $this->render($label, $count, $total);
            }
        }

        return $count;
    }

    private function finish(string $label, int $count, bool $render): void
    {
        if ($render) {
            $this->io->writeln();
        }
        if (!$this->io->quiet() && !$this->io->machineReadable()) {
            $this->io->success(sprintf('%s completed (%d).', $label, $count));
        }
    }

    private function render(string $label, int $current, ?int $total): void
    {
        if ($total === null) {
            $frame = self::SPINNER[$current % count(self::SPINNER)];
            $this->io->write(sprintf("\r%s %s %d", $frame, $label, $current));

            return;
        }

        $ratio = $total === 0 ? 1.0 : min(1.0, $current / $total);
        $width = 24;
        $filled = (int) floor($ratio * $width);
        $bar = str_repeat('#', $filled) . str_repeat('-', $width - $filled);
        $percent = (int) round($ratio * 100);
        $this->io->write(sprintf("\r[%s] %3d%% %s (%d/%d)", $bar, $percent, $label, $current, $total));
    }

    private function renderable(): bool
    {
        return !$this->io->quiet()
            && !$this->io->machineReadable()
            && $this->io->interactive();
    }

    private function reportFailure(string $label, bool $render, \Throwable $exception): void
    {
        if ($render) {
            $this->io->writeln();
        }
        $this->io->error(sprintf('%s failed: %s', $label, $exception->getMessage()));
    }
}
