<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

abstract class ManagerFacade extends Facade
{
    /**
     * @param list<mixed> $arguments
     */
    final public static function __callStatic(string $method, array $arguments): mixed
    {
        $manager = static::manager();

        if (!is_callable([$manager, $method])) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                $manager::class,
                $method,
            ));
        }

        return $manager->{$method}(...$arguments);
    }

    abstract public static function manager(): object;
}
