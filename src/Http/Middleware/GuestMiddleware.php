<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class GuestMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AuthResponseFactory $responses,
    ) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->principals->get() !== null) {
            return $this->responses->forbidden($request, 'Guests only.');
        }

        return $next($request);
    }
}
