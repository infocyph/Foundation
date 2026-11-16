<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Webrick\Response\Response;

/**
 * Runtime entry point discovered through native provider, route or benchmark metadata.
 *
 * @api
 */
final class FoundationMaintenanceBenchmarkHandler
{
    public function __invoke(): Response
    {
        return Response::create('ok');
    }
}
