<?php

declare(strict_types=1);

namespace App\Providers;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;

final class ClockProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $builder->value('app.timezone', 'UTC');
    }
}
