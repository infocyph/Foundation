<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Closure;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;

final class RunwireCollectingWriter implements ResponseWriterInterface
{
    public string $body = '';

    public int $endCalls = 0;

    public int $status = 0;

    private bool $ended = false;

    private bool $started = false;

    private ?Closure $terminal = null;

    public function end(string $finalChunk = ''): WriteResult
    {
        $this->body .= $finalChunk;
        ++$this->endCalls;
        $this->ended = true;
        ($this->terminal)?->__invoke($this);

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): self
    {
        unset($callback);

        return $this;
    }

    public function onTerminal(callable $callback): self
    {
        $this->terminal = Closure::fromCallable($callback);
        if ($this->ended) {
            ($this->terminal)($this);
        }

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($headers);
        $this->status = $status;
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        $this->body .= $chunk;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }
}
