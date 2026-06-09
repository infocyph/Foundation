<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

interface ServiceProviderInterface
{
    public function register(Application $app): void;

    public function boot(Application $app): void;
}
