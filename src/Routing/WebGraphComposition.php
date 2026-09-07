<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ContainerBuilder;

final readonly class WebGraphComposition
{
    public function __construct(
        public ContainerBuilder $builder,
        public Application $application,
        public FoundationBuildContext $context,
        public ConfigRepository $config,
    ) {}
}
