<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ExceptionRenderer
{
    public function __construct(
        private AuthExceptionMapper $auth,
    ) {}

    public static function supports(\Throwable $exception): bool
    {
        return AuthExceptionMapper::supportsDefault($exception);
    }

    public function render(
        Request $request,
        \Throwable $exception,
    ): ?Response {
        return $this->auth->supports($exception)
            ? $this->auth->toResponse($request, $exception)
            : null;
    }
}
