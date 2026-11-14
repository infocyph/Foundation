<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

/**
 * Public service-provider extension base for application composition.
 *
 * @api
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    #[\Override]
    public function boot(Application $app): void {}
}
