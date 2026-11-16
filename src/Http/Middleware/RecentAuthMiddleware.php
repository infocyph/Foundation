<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

final readonly class RecentAuthMiddleware extends AbstractPrincipalRequirementMiddleware
{
    /**
     * @return list<string>
     */
    #[\Override]
    protected function attributeKeys(): array
    {
        return ['auth.recent_auth', 'auth.recent'];
    }

    #[\Override]
    protected function failureMessage(): string
    {
        return 'Recent authentication is required.';
    }

    #[\Override]
    protected function metadataKey(): string
    {
        return 'recent_auth';
    }
}
