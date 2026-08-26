<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\Role;
use Infocyph\Foundation\Auth\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Authorization\Role\RoleStoreInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\PermissionMiddleware;
use Infocyph\Foundation\Http\Middleware\PolicyMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\RoleMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('uses ordinary account semantics for OAuth bearer principals across existing middleware', function (): void {
    $context = new CurrentPrincipalContext();
    $principal = new Principal(
        id: 'account-1',
        type: PrincipalType::ACCOUNT,
        accountId: 'account-1',
        metadata: [
            'auth_via' => 'oauth_bearer',
            'oauth_client_id' => 'oc_client',
            'oauth_scopes' => ['profile.read'],
            'oauth_audiences' => ['https://api.example.test'],
        ],
    );
    $context->set($principal);
    $responses = new AuthResponseFactory();
    $exceptions = new AuthExceptionMapper($responses);
    $accounts = new class implements AccountProviderInterface {
        public function findById(string $id): ?AccountInterface { return oauth21CompatibilityAccount($id); }
        public function findByIdentifier(string $identifier): ?AccountInterface { return null; }
    };
    $authorizer = new class implements AuthorizerInterface {
        public ?PrincipalInterface $seen = null;
        public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void { $this->seen = $principal; }
        public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision {
            $this->seen = $principal;
            return AuthorizationDecision::allow();
        }
    };
    $roles = new RoleManager(
        new class implements RoleStoreInterface {
            public function rolesForAccount(string $accountId): array { return [new Role('role-1', 'admin')]; }
        },
        new class implements RoleAssignmentStoreInterface {
            public function assignRole(string $accountId, string $roleId): void {}
            public function revokeRole(string $accountId, string $roleId): void {}
            public function save(Role $role): void {}
        },
        oauth21CompatibilityIds(),
    );
    $next = static fn(Request $request): Response => Response::plaintext('ok', 200);
    $request = Request::fake();

    expect((new AuthMiddleware($context, $responses))($request, $next)->getStatusCode())->toBe(200)
        ->and((new VerifiedMiddleware($context, $accounts, $responses))($request, $next)->getStatusCode())->toBe(200)
        ->and((new RoleMiddleware($context, $roles, $responses, ['admin']))($request, $next)->getStatusCode())->toBe(200)
        ->and((new PermissionMiddleware($context, $authorizer, $exceptions, $responses, ['profile.read']))($request, $next)->getStatusCode())->toBe(200)
        ->and($authorizer->seen)->toBe($principal)
        ->and((new PolicyMiddleware($context, $authorizer, $exceptions, $responses, 'profile.read'))($request, $next)->getStatusCode())->toBe(200)
        ->and($authorizer->seen)->toBe($principal);
});

it('does not give OAuth account principals alternate MFA or recent-auth semantics', function (): void {
    $context = new CurrentPrincipalContext();
    $context->set(new Principal(
        id: 'account-1',
        type: PrincipalType::ACCOUNT,
        accountId: 'account-1',
        metadata: ['auth_via' => 'oauth_bearer', 'oauth_client_id' => 'oc_client'],
    ));
    $responses = new AuthResponseFactory();
    $next = static fn(Request $request): Response => Response::plaintext('ok', 200);
    $request = Request::fake();

    expect((new MfaRequiredMiddleware($context, $responses))($request, $next)->getStatusCode())->toBe(403)
        ->and((new RecentAuthMiddleware($context, $responses))($request, $next)->getStatusCode())->toBe(403);

    $satisfied = $request
        ->withAttribute('auth.mfa_satisfied', true)
        ->withAttribute('auth.recent_auth', true);

    expect((new MfaRequiredMiddleware($context, $responses))($satisfied, $next)->getStatusCode())->toBe(200)
        ->and((new RecentAuthMiddleware($context, $responses))($satisfied, $next)->getStatusCode())->toBe(200);
});

it('keeps account-only middleware account-only for OAuth service principals', function (): void {
    $context = new CurrentPrincipalContext();
    $context->set(new Principal(
        id: 'oc_service',
        type: PrincipalType::SERVICE,
        accountId: null,
        metadata: ['auth_via' => 'oauth_bearer', 'oauth_client_id' => 'oc_service'],
    ));
    $responses = new AuthResponseFactory();
    $accounts = new class implements AccountProviderInterface {
        public function findById(string $id): ?AccountInterface { return oauth21CompatibilityAccount($id); }
        public function findByIdentifier(string $identifier): ?AccountInterface { return null; }
    };
    $roles = new RoleManager(
        new class implements RoleStoreInterface { public function rolesForAccount(string $accountId): array { return []; } },
        new class implements RoleAssignmentStoreInterface {
            public function assignRole(string $accountId, string $roleId): void {}
            public function revokeRole(string $accountId, string $roleId): void {}
            public function save(Role $role): void {}
        },
        oauth21CompatibilityIds(),
    );
    $next = static fn(Request $request): Response => Response::plaintext('ok', 200);
    $request = Request::fake();

    expect((new AuthMiddleware($context, $responses))($request, $next)->getStatusCode())->toBe(200)
        ->and((new VerifiedMiddleware($context, $accounts, $responses))($request, $next)->getStatusCode())->toBe(401)
        ->and((new RoleMiddleware($context, $roles, $responses, ['admin']))($request, $next)->getStatusCode())->toBe(401);
});

function oauth21CompatibilityAccount(string $id): AccountInterface
{
    return new readonly class($id) implements AccountInterface {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
        public function identifier(): string { return $this->id . '@example.test'; }
        public function metadata(): array { return []; }
        public function passwordHash(): ?string { return null; }
        public function status(): AccountStatus { return AccountStatus::ACTIVE; }
    };
}

function oauth21CompatibilityIds(): AuthIdGeneratorInterface
{
    return new class implements AuthIdGeneratorInterface {
        public function accountId(): string { return 'account-id'; }
        public function auditEventId(): string { return 'audit-id'; }
        public function challengeId(): string { return 'challenge-id'; }
        public function correlationId(): string { return 'correlation-id'; }
        public function credentialId(): string { return 'credential-id'; }
        public function deviceId(): string { return 'device-id'; }
        public function grantId(): string { return 'grant-id'; }
        public function permissionId(): string { return 'permission-id'; }
        public function roleId(): string { return 'role-id'; }
        public function sessionId(): string { return 'session-id'; }
    };
}
