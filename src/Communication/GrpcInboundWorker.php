<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRestartRequested;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundSource;
use Psr\Container\ContainerInterface;

/**
 * Foundation lifecycle adapter for one host-provided TalkingBytes inbound gRPC source.
 *
 * TalkingBytes owns accepted-exchange protocol adaptation. Foundation owns the
 * worker loop, execution scopes, heartbeat and release/restart decisions.
 */
final readonly class GrpcInboundWorker implements WorkerProvider
{
    public function __construct(
        private ContainerInterface $services,
        private ConfigRepository $config,
    ) {}

    public function run(WorkerRuntime $runtime): int
    {
        $sourceService = $this->sourceService();
        $source = $this->source($sourceService);
        $lastHeartbeat = self::monotonicNanoseconds();
        $heartbeatInterval = $this->heartbeatIntervalMilliseconds() * 1_000_000;

        $cancellation = CancellationSignal::fromCallable(
            function () use ($runtime, &$lastHeartbeat, $heartbeatInterval): bool {
                if ($runtime->stopRequested()) {
                    return true;
                }

                $now = self::monotonicNanoseconds();
                if (($now - $lastHeartbeat) < $heartbeatInterval) {
                    return false;
                }

                try {
                    $runtime->heartbeat();
                } catch (WorkerRestartRequested) {
                    return true;
                }
                $lastHeartbeat = $now;

                return $runtime->stopRequested();
            },
        );

        while (!$cancellation->isRequested()) {
            $served = $runtime->execute(
                function (ExecutionId $_executionId) use ($source, $cancellation): bool {
                    unset($_executionId);
                    $dispatcher = $this->services->get(GrpcInboundDispatcher::class);
                    if (!$dispatcher instanceof GrpcInboundDispatcher) {
                        throw new \LogicException('Foundation inbound gRPC dispatcher binding is invalid.');
                    }

                    return $dispatcher->serveOne($source, $cancellation);
                },
                ['foundation.grpc.inbound.source_service' => $sourceService],
            );

            if ($served || $cancellation->isRequested()) {
                continue;
            }

            $idleSleep = $this->idleSleepMilliseconds();
            if ($idleSleep > 0) {
                usleep($idleSleep * 1_000);
            }
        }

        return 0;
    }

    private static function monotonicNanoseconds(): int
    {
        $value = hrtime(true);

        return is_int($value) ? $value : (int) ((float) $value * 1_000_000_000);
    }

    private function heartbeatIntervalMilliseconds(): int
    {
        $milliseconds = ValueNormalizer::int(
            $this->config->get('communication.grpc.inbound.heartbeat_interval_milliseconds'),
            5_000,
        );
        if ($milliseconds < 1 || $milliseconds > 60_000) {
            throw new \InvalidArgumentException(
                'communication.grpc.inbound.heartbeat_interval_milliseconds must be between 1 and 60000.',
            );
        }

        return $milliseconds;
    }

    private function idleSleepMilliseconds(): int
    {
        $milliseconds = ValueNormalizer::int(
            $this->config->get('communication.grpc.inbound.idle_sleep_milliseconds'),
            10,
        );
        if ($milliseconds < 0 || $milliseconds > 60_000) {
            throw new \InvalidArgumentException(
                'communication.grpc.inbound.idle_sleep_milliseconds must be between 0 and 60000.',
            );
        }

        return $milliseconds;
    }

    private function source(string $service): GrpcInboundSource
    {
        try {
            $source = $this->services->get($service);
        } catch (\Throwable $exception) {
            throw new \LogicException(
                sprintf('Foundation inbound gRPC source service "%s" could not be resolved.', $service),
                previous: $exception,
            );
        }
        if (!$source instanceof GrpcInboundSource) {
            throw new \LogicException(sprintf(
                'Foundation inbound gRPC source service "%s" must implement %s.',
                $service,
                GrpcInboundSource::class,
            ));
        }

        return $source;
    }

    private function sourceService(): string
    {
        $service = $this->config->get('communication.grpc.inbound.source_service');
        if ($service === null || $service === '') {
            return GrpcInboundSource::class;
        }
        if (!is_string($service) || trim($service) === '') {
            throw new \InvalidArgumentException(
                'communication.grpc.inbound.source_service must be a non-empty service identifier.',
            );
        }

        return trim($service);
    }
}
