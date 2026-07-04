<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class RoleMiddleware
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private CurrentPrincipalContext $principals,
        private RoleManager $roleManager,
        private AuthResponseFactory $responses,
        private array $roles,
    ) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        $accountId = $principal?->accountId();

        if ($principal === null || $accountId === null || $accountId === '') {
            return $this->responses->unauthorized($request, 'Authentication is required.');
        }

        foreach ($this->roleManager->forAccount($accountId) as $role) {
            if (in_array($role->name, $this->roles, true)) {
                return $next($request);
            }
        }

        return $this->responses->forbidden($request, 'Role requirement was not satisfied.');
    }
}
