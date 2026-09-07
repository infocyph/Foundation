<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Webrick\Response\Response;

final readonly class Phase9ServerHandler
{
    public static function json(): Response
    {
        return Response::json(['ok' => true]);
    }
}
