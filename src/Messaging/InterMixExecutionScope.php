<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final readonly class InterMixExecutionScope implements ExecutionScope
{
    public function __construct(private Application $application) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $messageId = $envelope->last(MessageIdStamp::class);
        $executionId = $messageId instanceof MessageIdStamp
            ? new ExecutionId('omnibus:' . $messageId->id)
            : null;

        return $this->application->execution()->run(
            static fn(ExecutionId $id): mixed => $handler($envelope->message, $envelope),
            [
                Envelope::class => $envelope,
                $envelope->message::class => $envelope->message,
                'omnibus.message' => $envelope->message,
            ],
            $executionId,
        );
    }
}
