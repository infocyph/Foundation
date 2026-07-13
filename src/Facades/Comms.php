<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Communication\CommunicationManager;

/** @mixin CommunicationManager */
final class Comms extends ManagerFacade
{
    public static function manager(): CommunicationManager
    {
        return self::app()->communication();
    }
}
