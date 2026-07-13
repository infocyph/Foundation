<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Security\SecurityManager;

/**
 * @mixin SecurityManager
 */
final class Security extends ManagerFacade
{
    public static function manager(): SecurityManager
    {
        return self::app()->security();
    }
}
