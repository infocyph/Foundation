<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class AuthResponseFactory
{
    public function forbidden(Request $request, string $message = 'Forbidden.'): Response
    {
        return $this->respond($request, 403, $message);
    }

    public function locked(Request $request, string $message = 'Access is locked.'): Response
    {
        return $this->respond($request, 423, $message);
    }

    public function serverError(Request $request, string $message = 'Server error.'): Response
    {
        return $this->respond($request, 500, $message);
    }

    public function tooManyRequests(Request $request, string $message = 'Too many requests.'): Response
    {
        return $this->respond($request, 429, $message);
    }

    public function unauthorized(Request $request, string $message = 'Unauthorized.'): Response
    {
        return $this->respond($request, 401, $message);
    }

    private function respond(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'ok' => false,
                'message' => $message,
                'status' => $status,
            ], $status);
        }

        return Response::plaintext($message, $status);
    }
}
