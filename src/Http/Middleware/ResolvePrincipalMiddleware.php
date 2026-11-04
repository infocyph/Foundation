<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ResolvePrincipalMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private RequestPrincipalResolver $resolver,
    ) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $previous = $this->principals->get();
        $principal = $this->resolver->resolve($request);

        if ($principal !== null) {
            $this->principals->set($principal);
        } else {
            $this->principals->clear();
        }

        if ($principal !== null) {
            $request = $request->withAttributes([
                'auth.account_id' => $principal->accountId(),
                'auth.principal' => $principal,
                'auth.principal_id' => $principal->id(),
                'auth.principal_type' => $principal->type()->value,
            ]);
        }

        try {
            return $next($request);
        } finally {
            if ($previous !== null) {
                $this->principals->set($previous);
            } else {
                $this->principals->clear();
            }
        }
    }
}
