<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class RecentAuthMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AuthResponseFactory $responses,
    ) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        if ($principal === null) {
            return $this->responses->unauthorized($request, 'Authentication is required.');
        }

        $recent = $request->getAttribute('auth.recent_auth')
            ?? $request->getAttribute('auth.recent')
            ?? $principal->metadata()['recent_auth'] ?? false;

        if ($recent !== true) {
            return $this->responses->forbidden($request, 'Recent authentication is required.');
        }

        return $next($request);
    }
}
