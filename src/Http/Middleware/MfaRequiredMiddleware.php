<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

final readonly class MfaRequiredMiddleware extends AbstractPrincipalRequirementMiddleware
{
    /**
     * @return list<string>
     */
    protected function attributeKeys(): array
    {
        return ['auth.mfa_satisfied', 'auth.mfa'];
    }

    protected function failureMessage(): string
    {
        return 'Multi-factor verification is required.';
    }

    protected function metadataKey(): string
    {
        return 'mfa_satisfied';
    }
}
