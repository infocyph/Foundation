<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ContainerBuilder;

/** Build-time composition state for CLI, worker, and scheduler runtimes. */
final readonly class NonWebGraphComposition
{
    public function __construct(
        public ContainerBuilder $builder,
        public Application $application,
        public FoundationBuildContext $context,
        public ConfigRepository $config,
        public ServiceRegistry $providers,
        public Bootstrapper $bootstrapper,
    ) {}
}
