<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process\Internal;

use Infocyph\Foundation\Process\ProcessOptions;

final class ProcessSignals
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_TERMINATE = 15;

    /**
     * @param callable(int):void $interrupt
     * @return array{async:bool,handlers:array<int,callable|int>}|null
     */
    public function register(ProcessOptions $options, callable $interrupt): ?array
    {
        if (!$options->handleSignals
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return null;
        }

        $handlers = [];
        foreach ([self::SIGNAL_INTERRUPT, self::SIGNAL_TERMINATE] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }

        $async = pcntl_async_signals();
        pcntl_async_signals(true);
        foreach (array_keys($handlers) as $signal) {
            pcntl_signal(
                $signal,
                static function (int $received) use ($interrupt): void {
                    $interrupt($received);
                },
                false,
            );
        }

        return ['async' => $async, 'handlers' => $handlers];
    }

    /** @param array{async:bool,handlers:array<int,callable|int>}|null $state */
    public function restore(?array $state): void
    {
        if ($state === null || !function_exists('pcntl_signal')) {
            return;
        }

        foreach ($state['handlers'] as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals($state['async']);
        }
    }
}
