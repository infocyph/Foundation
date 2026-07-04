<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Application $app): void {}
}
