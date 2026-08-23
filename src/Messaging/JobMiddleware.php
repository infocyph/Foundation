<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

interface JobMiddleware
{
    /** @param callable(): mixed $next */
    public function process(Job $job, JobContext $context, callable $next): mixed;
}
