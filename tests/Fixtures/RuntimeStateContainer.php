<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalState;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

/**
 * Minimal container for tests that exercise a stateful adapter directly.
 *
 * Integration tests use real InterMix scopes. This fixture is reserved for
 * focused adapter tests whose subject needs one execution-owned state object.
 */
final readonly class RuntimeStateContainer implements ContainerInterface
{
    /** @param array<class-string, object> $services */
    public function __construct(private array $services) {}

    public static function execution(): self
    {
        return new self([
            RuntimeExecutionState::class => new RuntimeExecutionState(),
        ]);
    }

    public static function principal(): self
    {
        return new self([
            CurrentPrincipalState::class => new CurrentPrincipalState(),
        ]);
    }

    public function get(string $id): mixed
    {
        return $this->services[$id]
            ?? throw new \LogicException(sprintf('Fixture container has no service "%s".', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
