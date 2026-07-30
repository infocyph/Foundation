<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Runtime\RuntimeContextResetter;
use Infocyph\InterMix\DI\Container;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final readonly class InterMixExecutionScope implements ExecutionScope
{
    public function __construct(
        private Container $container,
        private RuntimeContextResetter $contexts,
    ) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $messageId = $envelope->last(MessageIdStamp::class);
        $scope = $messageId instanceof MessageIdStamp
            ? 'omnibus:' . $messageId->id
            : 'omnibus:' . bin2hex(random_bytes(12));

        $this->container->enterScope($scope, [
            Envelope::class => $envelope,
            $envelope->message::class => $envelope->message,
            'omnibus.message' => $envelope->message,
        ]);

        try {
            return $handler($envelope->message, $envelope);
        } finally {
            try {
                $this->contexts->reset();
            } finally {
                $this->container->leaveScope();
            }
        }
    }
}
