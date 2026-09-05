<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\InterMix\DI\Container;

/** Stateless scope-leave hook retained by the mutable development graph. */
final class RuntimeScopeCleanup
{
    public static function handle(string $scope, Container $container): void
    {
        unset($scope);

        $state = $container->get(RuntimeExecutionState::class);
        if ($state instanceof RuntimeExecutionState) {
            $state->cleanup(false);
        }
    }
}
