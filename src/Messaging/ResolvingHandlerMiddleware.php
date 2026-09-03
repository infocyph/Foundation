<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Psr\Container\ContainerInterface;

/** Resolves application-configured handler middleware inside the active execution scope. */
final readonly class ResolvingHandlerMiddleware implements HandlerMiddleware
{
    public function __construct(
        private ContainerInterface $container,
        private string $service,
    ) {}

    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed {
        $middleware = $this->container->get($this->service);
        if (!$middleware instanceof HandlerMiddleware) {
            throw new \InvalidArgumentException(sprintf(
                'Messaging handler middleware "%s" must implement %s.',
                $this->service,
                HandlerMiddleware::class,
            ));
        }

        return $middleware->process($message, $envelope, $context, $next);
    }
}
