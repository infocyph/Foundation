<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Scope;

final readonly class OAuthScopeSelection
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param list<string> $permissions
     */
    public function __construct(
        public array $scopes,
        public array $audiences,
        public array $permissions = [],
    ) {}
}
