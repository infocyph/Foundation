<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class MfaRequiredMiddleware
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

        $satisfied = $request->getAttribute('auth.mfa_satisfied')
            ?? $request->getAttribute('auth.mfa')
            ?? $principal->metadata()['mfa_satisfied'] ?? false;

        if ($satisfied !== true) {
            return $this->responses->forbidden($request, 'Multi-factor verification is required.');
        }

        return $next($request);
    }
}
