<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

abstract readonly class AbstractPrincipalRequirementMiddleware
{
    public function __construct(
        protected CurrentPrincipalContext $principals,
        protected AuthResponseFactory $responses,
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

        if ($this->requirementSatisfied($request, $principal->metadata()) !== true) {
            return $this->responses->forbidden($request, $this->failureMessage());
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    abstract protected function attributeKeys(): array;

    abstract protected function failureMessage(): string;

    abstract protected function metadataKey(): string;

    /**
     * @param array<string, mixed> $metadata
     */
    private function requirementSatisfied(Request $request, array $metadata): mixed
    {
        foreach ($this->attributeKeys() as $key) {
            $value = $request->getAttribute($key);
            if ($value !== null) {
                return $value;
            }
        }

        return $metadata[$this->metadataKey()] ?? false;
    }
}
