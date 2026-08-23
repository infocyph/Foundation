<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\ReqShield\Exceptions\ValidationException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class ValidationExceptionMapper
{
    public static function supportsDefault(\Throwable $exception): bool
    {
        return $exception instanceof ValidationException;
    }

    public function supports(\Throwable $exception): bool
    {
        return self::supportsDefault($exception);
    }

    public function toResponse(Request $request, \Throwable $exception): ?Response
    {
        if (!$exception instanceof ValidationException) {
            return null;
        }

        if ($request->expectsJson()) {
            return Response::json([
                'ok' => false,
                'message' => 'Validation failed.',
                'status' => 422,
                'errors' => $exception->getErrors(),
            ], 422);
        }

        return Response::plaintext('Validation failed.', 422);
    }
}
