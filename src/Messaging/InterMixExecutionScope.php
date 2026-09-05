<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\ExecutionScope as FoundationExecutionScope;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final readonly class InterMixExecutionScope implements ExecutionScope
{
    public function __construct(private FoundationExecutionScope $execution) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $messageId = $envelope->last(MessageIdStamp::class);
        $executionId = $messageId instanceof MessageIdStamp
            ? new ExecutionId('omnibus:' . $messageId->id)
            : null;

        return $this->execution->run(
            static fn(): mixed => $handler($envelope->message, $envelope),
            [
                Envelope::class => $envelope,
                $envelope->message::class => $envelope->message,
                'omnibus.message' => $envelope->message,
            ],
            $executionId,
        );
    }
}
