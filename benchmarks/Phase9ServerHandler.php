<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Webrick\Response\Response;
use RuntimeException;

final readonly class Phase9ServerHandler
{
    public static function failure(): Response
    {
        throw new RuntimeException('Foundation host request failure probe.');
    }

    public static function json(): Response
    {
        return Response::json(['ok' => true]);
    }

    public static function slow(): Response
    {
        usleep(250_000);

        return Response::json(['ok' => true]);
    }
}
