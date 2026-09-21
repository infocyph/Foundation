<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Webrick\Response\Response;

final class FoundationMaintenanceBenchmarkHandler
{
    public function __invoke(): Response
    {
        return Response::create('ok');
    }
}
