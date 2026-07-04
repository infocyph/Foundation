<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\FoundationException;

abstract class Facade
{
    protected static ?Application $app = null;

    public static function setApplication(Application $app): void
    {
        static::$app = $app;
    }

    protected static function app(): Application
    {
        return static::$app ?? throw new FoundationException('No Foundation application has been set.');
    }
}
