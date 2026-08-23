<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerMiddleware;

final readonly class JobMiddlewarePipeline implements HandlerMiddleware
{
    /** @var list<JobMiddleware> */
    private array $middleware;

    /** @param iterable<JobMiddleware> $middleware */
    public function __construct(iterable $middleware)
    {
        $normalized = [];
        foreach ($middleware as $candidate) {
            if (!$candidate instanceof JobMiddleware) {
                throw new \InvalidArgumentException(sprintf(
                    'Job middleware must implement %s.',
                    JobMiddleware::class,
                ));
            }
            $normalized[] = $candidate;
        }
        $this->middleware = $normalized;
    }

    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed {
        if (!$message instanceof Job || $this->middleware === []) {
            return $next($message, $envelope, $context);
        }

        $jobContext = new JobContext(
            queue: $context->queue,
            attempt: $context->attempt,
            asynchronous: $context->asynchronous,
        );
        $terminal = static fn(): mixed => $next($message, $envelope, $context);

        for ($index = count($this->middleware) - 1; $index >= 0; $index--) {
            $middleware = $this->middleware[$index];
            $nextJob = $terminal;
            $terminal = static fn(): mixed => $middleware->process($message, $jobContext, $nextJob);
        }

        return $terminal();
    }
}
