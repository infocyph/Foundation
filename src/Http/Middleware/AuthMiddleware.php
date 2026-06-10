<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class AuthMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AuthResponseFactory $responses,
    ) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->principals->get() === null) {
            return $this->responses->unauthorized($request, 'Authentication is required.');
        }

        return $next($request);
    }
}
