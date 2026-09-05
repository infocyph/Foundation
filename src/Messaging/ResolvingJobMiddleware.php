<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Psr\Container\ContainerInterface;

/** Resolves application-configured job middleware inside the active execution scope. */
final readonly class ResolvingJobMiddleware implements JobMiddleware
{
    public function __construct(
        private ContainerInterface $container,
        private string $service,
    ) {}

    public function process(Job $job, JobContext $context, callable $next): mixed
    {
        $middleware = $this->container->get($this->service);
        if (!$middleware instanceof JobMiddleware) {
            throw new \InvalidArgumentException(sprintf(
                'Messaging job middleware "%s" must implement %s.',
                $this->service,
                JobMiddleware::class,
            ));
        }

        return $middleware->process($job, $context, $next);
    }
}
