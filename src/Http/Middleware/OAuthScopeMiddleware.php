<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthScopeMiddleware
{
    /** @param list<string> $requiredScopes */
    public function __construct(
        private CurrentPrincipalContext $principals,
        private array $requiredScopes,
    ) {
        if ($this->requiredScopes === [] || !array_is_list($this->requiredScopes)) {
            throw new \InvalidArgumentException('OAuth scope middleware requires at least one scope.');
        }
        foreach ($this->requiredScopes as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new \InvalidArgumentException('OAuth scope middleware received an invalid scope.');
            }
        }
    }

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        $metadata = $principal?->metadata() ?? [];
        if (($metadata['auth_via'] ?? null) !== 'oauth_bearer') {
            return $this->unauthorized('An OAuth bearer token is required.');
        }

        $granted = $this->stringList($metadata['oauth_scopes'] ?? null);
        foreach ($this->requiredScopes as $required) {
            if (!in_array($required, $granted, true)) {
                return $this->insufficientScope();
            }
        }

        return $next($request);
    }

    private function insufficientScope(): Response
    {
        return Response::json(
            ['error' => 'insufficient_scope', 'error_description' => 'The bearer token lacks a required scope.'],
            403,
            [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
                'WWW-Authenticate' => sprintf(
                    'Bearer error="insufficient_scope", scope="%s"',
                    implode(' ', $this->requiredScopes),
                ),
            ],
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }

    private function unauthorized(string $description): Response
    {
        return Response::json(
            ['error' => 'invalid_token', 'error_description' => $description],
            401,
            [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
            ],
        );
    }
}
