<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\Webrick\Response\Response;

final class ServeProbe
{
    public static function failure(): never
    {
        throw new \RuntimeException('serve-probe-private-error');
    }

    public static function read(): Response
    {
        $shared = RunwireIntegration::current();

        return Response::json([
            'pid' => getmypid(),
            'runtime_pid' => $shared?->runtime?->pid,
            'worker_slot' => $shared?->runtime?->workerSlot,
            'request_id' => $shared?->request?->requestId,
            'sapi' => PHP_SAPI,
        ]);
    }
}
