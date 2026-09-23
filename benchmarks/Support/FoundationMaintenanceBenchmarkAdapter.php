<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;

final class FoundationMaintenanceBenchmarkAdapter implements RuntimeAdapterInterface
{
    private readonly RuntimeCapabilities $runtimeCapabilities;

    public int $requestMaterializations = 0;

    public function __construct()
    {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'foundation-maintenance-benchmark',
            persistent: true,
            concurrent: true,
            nativeStreaming: true,
            nativeFile: true,
        );
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(
        mixed $nativeRequest = null,
        mixed $nativeResponse = null,
        bool $withHost = false,
    ): RuntimeRequestContext {
        unset($nativeRequest, $nativeResponse);
        $host = $withHost ? 'benchmark.test' : '*';

        return new RuntimeRequestContext(
            new RoutingInput('GET', '/benchmark', $host),
            function (): Request {
                ++$this->requestMaterializations;

                return Request::fake(
                    headers: ['Host' => 'benchmark.test'],
                    uri: 'https://benchmark.test/benchmark',
                );
            },
            $this->runtimeCapabilities,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        unset($context);
        if ($response->getStatusCode() !== 200 || $response->getStringBody() !== 'ok') {
            throw new \LogicException(sprintf(
                'Maintenance benchmark produced status %d with body %s.',
                $response->getStatusCode(),
                json_encode($response->getStringBody(), JSON_THROW_ON_ERROR),
            ));
        }
    }
}
