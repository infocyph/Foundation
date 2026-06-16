<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Gate;

use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantResolver;
use Infocyph\Foundation\Auth\Authorization\Permission\Permission;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionResolver;
use Infocyph\Foundation\Auth\Authorization\Role\RolePermissionResolver;
use Infocyph\Foundation\Auth\Exception\AuthorizationException;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class PermissionAuthorizer implements AuthorizerInterface
{
    public function __construct(
        private PermissionResolver $permissions,
        private RolePermissionResolver $rolePermissions,
        private GrantResolver $grants,
        private AbilityMatcher $matcher = new AbilityMatcher(),
        private bool $allowGuests = false,
    ) {}

    public function authorize(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource = null,
        array $context = [],
    ): void {
        $decision = $this->can($principal, $ability, $resource, $context);

        if (!$decision->allowed) {
            throw new AuthorizationException($decision->reason ?? 'Authorization failed.', $decision->code);
        }
    }

    public function can(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource = null,
        array $context = [],
    ): AuthorizationDecision {
        if ($principal->accountId() === null) {
            return $this->allowGuests
                ? AuthorizationDecision::allow('guest_allowed')
                : AuthorizationDecision::deny('guest_forbidden', 'Guest principals cannot access this ability.', $context);
        }

        $requestedAbility = Ability::from($ability, $resource, $context);

        foreach ($this->directAndRolePermissions($principal->accountId()) as $permission) {
            if ($this->matcher->matches($permission->name, $requestedAbility->name)) {
                return AuthorizationDecision::allow('permission_granted', context: ['permission' => $permission->name] + $context);
            }
        }

        $grantMatches = $this->grants->forPrincipal($principal->id(), $requestedAbility);

        if ($grantMatches !== []) {
            return AuthorizationDecision::allow('grant_granted', context: ['grant_count' => count($grantMatches)] + $context);
        }

        return AuthorizationDecision::deny('permission_denied', 'No matching permission or grant was found.', $context);
    }

    /**
     * @return list<Permission>
     */
    private function directAndRolePermissions(string $accountId): array
    {
        $permissions = [];

        foreach ($this->permissions->forAccount($accountId) as $permission) {
            $permissions[$permission->name] = $permission;
        }

        foreach ($this->rolePermissions->forAccount($accountId) as $permission) {
            $permissions[$permission->name] = $permission;
        }

        return array_values($permissions);
    }
}
