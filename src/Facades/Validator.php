<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Validation\ValidationManager;

/** @mixin ValidationManager */
final class Validator extends ManagerFacade
{
    public static function manager(): ValidationManager
    {
        return self::app()->validator();
    }
}
