<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;

final class Json extends ManagerFacade
{
    public static function manager(): JsonDispatchResponseFactory
    {
        return self::app()->responses();
    }
}
