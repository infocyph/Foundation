<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Identifiers\IdentifierManager;

/**
 * @mixin IdentifierManager
 */
final class Ids extends ManagerFacade
{
    public static function manager(): IdentifierManager
    {
        return self::app()->ids();
    }
}
