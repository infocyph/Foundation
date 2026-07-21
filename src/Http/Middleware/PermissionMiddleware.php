<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class PermissionMiddleware
{
    /**
     * @param list<string> $abilities
     */
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AuthorizerInterface $authorizer,
        private AuthExceptionMapper $exceptions,
        private AuthResponseFactory $responses,
        private array $abilities,
    ) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        if ($principal === null) {
            return $this->responses->unauthorized($request, 'Authentication is required.');
        }

        foreach ($this->abilities as $ability) {
            try {
                if ($this->authorizer->can($principal, $ability)->allowed) {
                    return $next($request);
                }
            } catch (\Throwable $e) {
                return $this->exceptions->toResponse($request, $e);
            }
        }

        return $this->responses->forbidden($request, 'Permission denied.');
    }
}
