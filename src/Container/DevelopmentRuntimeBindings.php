<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\Application;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

/** Application identity is attached only after the complete development graph exists. */
final class DevelopmentRuntimeBindings
{
    private ?Application $application = null;

    public static function register(ContainerBuilder $builder): self
    {
        $bindings = new self();
        $builder->bindFactory(Application::class, static fn(): Application => $bindings->application());
        $builder->bindFactory(Container::class, static fn(Container $runtime): Container => $runtime);

        return $bindings;
    }

    public function attach(Application $application): void
    {
        if ($this->application !== null) {
            throw new \LogicException('Development application identity is already attached.');
        }

        $this->application = $application;
    }

    private function application(): Application
    {
        return $this->application
            ?? throw new \LogicException('Development application identity is not attached.');
    }
}
