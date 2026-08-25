<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ExceptionRenderer
{
    public function __construct(
        private AuthExceptionMapper $auth,
        private ValidationExceptionMapper $validation,
    ) {}

    public static function supports(\Throwable $exception): bool
    {
        return AuthExceptionMapper::supportsDefault($exception)
            || ValidationExceptionMapper::supportsDefault($exception);
    }

    public function render(
        Request $request,
        \Throwable $exception,
    ): ?Response {
        if ($this->validation->supports($exception)) {
            return $this->validation->toResponse($request, $exception);
        }

        return $this->auth->supports($exception)
            ? $this->auth->toResponse($request, $exception)
            : null;
    }
}
