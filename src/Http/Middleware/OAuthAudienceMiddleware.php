<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthAudienceMiddleware
{
    /** @param list<string> $requiredAudiences */
    public function __construct(
        private CurrentPrincipalContext $principals,
        private array $requiredAudiences,
    ) {
        if ($this->requiredAudiences === []) {
            throw new \InvalidArgumentException('OAuth audience middleware requires at least one audience.');
        }
        foreach ($this->requiredAudiences as $audience) {
            if ($audience === '') {
                throw new \InvalidArgumentException('OAuth audience middleware received an invalid audience.');
            }
        }
    }

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        $metadata = $principal?->metadata() ?? [];
        if (($metadata['auth_via'] ?? null) !== 'oauth_bearer') {
            return $this->invalidToken('An OAuth bearer token is required.');
        }

        $granted = $this->stringList($metadata['oauth_audiences'] ?? null);
        foreach ($this->requiredAudiences as $required) {
            if (in_array($required, $granted, true)) {
                return $next($request);
            }
        }

        return $this->invalidToken('The bearer token is not valid for this resource audience.');
    }

    private function invalidToken(string $description): Response
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

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }
}
