<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

abstract class AbstractInMemoryConsumableStore
{
    /**
     * @var array<string, object>
     */
    protected array $requests = [];

    public function __construct(
        protected readonly ClockInterface $clock = new SystemClock(),
    ) {}

    abstract protected function consumeRequest(object $request, int $consumedAt): object;

    protected function consumeStored(string $requestId): void
    {
        $request = $this->requests[$requestId] ?? null;

        if ($request === null) {
            return;
        }

        $this->requests[$requestId] = $this->consumeRequest($request, $this->clock->now());
    }

    protected function findStored(string $requestId): ?object
    {
        return $this->requests[$requestId] ?? null;
    }

    protected function saveStored(object $request, string $requestId): void
    {
        $this->requests[$requestId] = $request;
    }
}
