<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\InterMix\DI\ContainerBuilder;

interface ServiceProviderInterface
{
    /**
     * Contribute this provider's immutable service graph before a runtime
     * container is created or compiled.
     */
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void;

    /**
     * Run process-level side effects after the finalized runtime graph exists.
     * Service definitions must never be mutated from boot().
     */
    public function boot(Application $app): void;
}
