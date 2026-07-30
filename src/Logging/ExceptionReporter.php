<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

use Psr\Log\LoggerInterface;

final class ExceptionReporter
{
    /** @var array<string, array{count:int,started_at:int}> */
    private array $throttleWindows = [];

    /**
     * @param list<class-string<\Throwable>> $ignoredExceptions
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $includeMessage = false,
        private readonly array $ignoredExceptions = [],
        private readonly float $sampleRate = 1.0,
        private readonly int $throttleSeconds = 0,
        private readonly int $throttleLimit = 1,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function report(string $level, array $context): void
    {
        $exception = $context['exception'] ?? null;
        $status = is_int($context['status'] ?? null) ? $context['status'] : 500;
        if (!$this->shouldReport($exception, $status)) {
            return;
        }

        $exceptionClass = $exception instanceof \Throwable ? $exception::class : \Throwable::class;
        if (!$this->includeMessage && $exception instanceof \Throwable) {
            $context['exception'] = [
                'class' => $exceptionClass,
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $this->logger->log(
            $level,
            sprintf('[http:%d] %s', $status, $exceptionClass),
            $context,
        );
    }

    private function ignored(\Throwable $exception): bool
    {
        return array_any(
            $this->ignoredExceptions,
            static fn(string $class): bool => $exception instanceof $class,
        );
    }

    private function sampled(): bool
    {
        return $this->sampleRate >= 1.0
            || ($this->sampleRate > 0.0 && mt_rand() / mt_getrandmax() <= $this->sampleRate);
    }

    private function shouldReport(mixed $exception, int $status): bool
    {
        if ($exception instanceof \Throwable && $this->ignored($exception)) {
            return false;
        }
        if (!$this->sampled()) {
            return false;
        }
        if ($this->throttleSeconds <= 0) {
            return true;
        }

        $class = $exception instanceof \Throwable ? $exception::class : \Throwable::class;
        $file = $exception instanceof \Throwable ? $exception->getFile() : '';
        $line = $exception instanceof \Throwable ? $exception->getLine() : 0;
        $signature = $class . ':' . $file . ':' . $line . ':' . $status;
        $now = time();
        $window = $this->throttleWindows[$signature] ?? null;
        if ($window === null || $now - $window['started_at'] >= $this->throttleSeconds) {
            if (count($this->throttleWindows) >= 256) {
                array_shift($this->throttleWindows);
            }
            $this->throttleWindows[$signature] = ['count' => 1, 'started_at' => $now];

            return true;
        }

        ++$this->throttleWindows[$signature]['count'];

        return $this->throttleWindows[$signature]['count'] <= $this->throttleLimit;
    }
}
