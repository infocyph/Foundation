<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Gate;

use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Policy\PolicyInterface;
use Infocyph\Foundation\Auth\Authorization\Policy\PolicyResolverInterface;
use Infocyph\Foundation\Auth\Exception\AuthorizationException;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final class Gate implements AuthorizerInterface
{
    /** @var array<string, callable(PrincipalInterface, mixed, array<string, mixed>): (AuthorizationDecision|bool|null)> */
    private array $abilities = [];

    /** @var list<callable(PrincipalInterface, string, mixed, AuthorizationDecision, array<string, mixed>): (AuthorizationDecision|bool|null)> */
    private array $afterCallbacks = [];

    /** @var list<callable(PrincipalInterface, string, mixed, array<string, mixed>): (AuthorizationDecision|bool|null)> */
    private array $beforeCallbacks = [];

    public function __construct(
        private readonly ?PolicyResolverInterface $policyResolver = null,
    ) {}

    /**
     * @param callable(PrincipalInterface, string, mixed, AuthorizationDecision, array<string, mixed>): (AuthorizationDecision|bool|null) $callback
     */
    public function after(callable $callback): self
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    public function authorize(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource = null,
        array $context = [],
    ): void {
        $decision = $this->can($principal, $ability, $resource, $context);

        if (!$decision->allowed) {
            throw new AuthorizationException(
                $decision->reason ?? 'Authorization failed.',
                $decision->code,
            );
        }
    }

    /**
     * @param callable(PrincipalInterface, string, mixed, array<string, mixed>): (AuthorizationDecision|bool|null) $callback
     */
    public function before(callable $callback): self
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    public function can(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource = null,
        array $context = [],
    ): AuthorizationDecision {
        foreach ($this->beforeCallbacks as $callback) {
            $result = $callback($principal, $ability, $resource, $context);

            if ($result === null) {
                continue;
            }

            return $this->runAfterCallbacks(
                $principal,
                $ability,
                $resource,
                $context,
                $this->normalizeDecision($result),
            );
        }

        $decision = $this->resolveDecision($principal, $ability, $resource, $context);

        return $this->runAfterCallbacks($principal, $ability, $resource, $context, $decision);
    }

    /**
     * @param callable(PrincipalInterface, mixed, array<string, mixed>): (AuthorizationDecision|bool|null) $callback
     */
    public function define(string $ability, callable $callback): self
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    private function normalizeDecision(AuthorizationDecision|bool|null $decision): AuthorizationDecision
    {
        return match (true) {
            $decision instanceof AuthorizationDecision => $decision,
            $decision === true => AuthorizationDecision::allow(),
            default => AuthorizationDecision::deny(),
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveDecision(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource,
        array $context,
    ): AuthorizationDecision {
        if (array_key_exists($ability, $this->abilities)) {
            $result = ($this->abilities[$ability])($principal, $resource, $context);

            return $this->normalizeDecision($result);
        }

        $policy = $this->resolvePolicy($resource);

        if ($policy !== null) {
            return $this->normalizeDecision(
                $policy->authorize($principal, $ability, $resource, $context),
            );
        }

        return AuthorizationDecision::deny(
            code: 'ability_not_defined',
            reason: sprintf('No gate or policy resolved for ability "%s".', $ability),
        );
    }

    private function resolvePolicy(mixed $resource): ?PolicyInterface
    {
        if ($resource === null || $this->policyResolver === null) {
            return null;
        }

        return $this->policyResolver->resolve($resource);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runAfterCallbacks(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource,
        array $context,
        AuthorizationDecision $decision,
    ): AuthorizationDecision {
        foreach ($this->afterCallbacks as $callback) {
            $result = $callback($principal, $ability, $resource, $decision, $context);

            if ($result === null) {
                continue;
            }

            $decision = $this->normalizeDecision($result);
        }

        return $decision;
    }
}
