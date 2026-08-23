<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\Receiver;
use Infocyph\Omnibus\Transport\TransportRegistry;

final readonly class ConsumerFactory
{
    public function __construct(
        private ConfigRepository $config,
        private TransportRegistry $transports,
        private HandlerInvoker $invoker,
        private FailureStore $failures,
        private SystemClock $clock,
        private ExecutionScope $scope,
    ) {}

    public function make(?string $transport = null): Consumer
    {
        $name = $transport ?? ValueNormalizer::string(
            $this->config->get('messaging.consumer.transport'),
            'memory',
        );
        $receiver = $this->transports->get($name);
        if (!$receiver instanceof Receiver) {
            throw new \LogicException(sprintf(
                'Messaging consumer transport "%s" cannot receive messages.',
                $name,
            ));
        }

        return new Consumer(
            receiver: $receiver,
            invoker: $this->invoker,
            retry: new ExponentialRetryStrategy(
                maximumAttempts: ValueNormalizer::int(
                    $this->config->get('messaging.retry.maximum_attempts'),
                    3,
                ),
                initialDelaySeconds: $this->float(
                    $this->config->get('messaging.retry.initial_delay_seconds'),
                    1.0,
                ),
                multiplier: $this->float(
                    $this->config->get('messaging.retry.multiplier'),
                    2.0,
                ),
                maximumDelaySeconds: $this->float(
                    $this->config->get('messaging.retry.maximum_delay_seconds'),
                    60.0,
                ),
                jitterRatio: $this->float(
                    $this->config->get('messaging.retry.jitter_ratio'),
                    0.0,
                ),
            ),
            failures: $this->failures,
            clock: $this->clock,
            scope: $this->scope,
        );
    }

    private function float(mixed $value, float $default): float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : $default;
    }
}
