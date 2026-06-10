<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ResolvePrincipalMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private RequestPrincipalResolver $resolver,
    ) {}

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
            $request = $request
                ->withAttribute('auth.account_id', $principal->accountId())
                ->withAttribute('auth.principal', $principal)
                ->withAttribute('auth.principal_id', $principal->id())
                ->withAttribute('auth.principal_type', $principal->type()->value);
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
