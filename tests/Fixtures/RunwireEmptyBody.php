<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\RequestBodyInterface;

final class RunwireEmptyBody implements RequestBodyInterface
{
    #[\Override]
    public function bufferedBytes(): int
    {
        return 0;
    }

    #[\Override]
    public function eof(): bool
    {
        return true;
    }

    #[\Override]
    public function onData(callable $callback): self
    {
        unset($callback);

        return $this;
    }

    #[\Override]
    public function onEnd(callable $callback): self
    {
        $callback($this);

        return $this;
    }

    #[\Override]
    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        unset($maxBytes);

        return '';
    }

    #[\Override]
    public function receivedBytes(): int
    {
        return 0;
    }

    #[\Override]
    public function trailers(): Headers
    {
        return new Headers();
    }
}
