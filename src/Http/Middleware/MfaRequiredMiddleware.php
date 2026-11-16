<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

final readonly class MfaRequiredMiddleware extends AbstractPrincipalRequirementMiddleware
{
    /**
     * @return list<string>
     */
    #[\Override]
    protected function attributeKeys(): array
    {
        return ['auth.mfa_satisfied', 'auth.mfa'];
    }

    #[\Override]
    protected function failureMessage(): string
    {
        return 'Multi-factor verification is required.';
    }

    #[\Override]
    protected function metadataKey(): string
    {
        return 'mfa_satisfied';
    }
}
