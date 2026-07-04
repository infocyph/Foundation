<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class PolicyMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AuthorizerInterface $authorizer,
        private AuthExceptionMapper $exceptions,
        private AuthResponseFactory $responses,
        private string $ability,
        private ?string $resourceKey = null,
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

        $params = $request->getAttribute('route.params', []);
        $resource = is_array($params) && $this->resourceKey !== null
            ? ($params[$this->resourceKey] ?? null)
            : null;

        try {
            $this->authorizer->authorize($principal, $this->ability, $resource);
        } catch (\Throwable $e) {
            return $this->exceptions->toResponse($request, $e);
        }

        return $next($request);
    }
}
