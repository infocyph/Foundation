<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

use Psr\Log\AbstractLogger;

final class HttpExceptionLogger extends AbstractLogger
{
    public function __construct(
        private readonly ExceptionReporter $reporter,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $context['logger_message_type'] = is_string($message) ? 'string' : $message::class;
        $this->reporter->report(is_string($level) ? $level : 'error', $context);
    }
}
