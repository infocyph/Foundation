<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Runtime\ForkSafetyTracker;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

/** Application identity is attached only after the complete development graph exists. */
final class DevelopmentRuntimeBindings
{
    private ?Application $application = null;

    public static function register(ContainerBuilder $builder): self
    {
        $bindings = new self();
        $builder->factory(Application::class, static fn(): Application => $bindings->application());
        $builder->factory(Container::class, static fn(Container $runtime): Container => $runtime);
        $tracker = new ForkSafetyTracker();
        $builder->value(ForkSafetyTracker::class, $tracker);
        foreach ([
            \Infocyph\Foundation\Cache\CacheManager::class,
            \Infocyph\CacheLayer\Cache\CacheInterface::class,
            \Infocyph\CacheLayer\Cache\Cache::class,
            \Infocyph\CacheLayer\Cache\Lock\LockProviderInterface::class,
            \Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface::class,
            \Infocyph\DBLayer\Connection\Connection::class,
            \Infocyph\Omnibus\Consumer\Consumer::class,
            \Infocyph\Omnibus\Failure\FailureStore::class,
            \Infocyph\Omnibus\Transport\TransportRegistry::class,
            \Infocyph\TalkingBytes\Http\HttpClient::class,
            \Infocyph\Foundation\Runtime\RuntimeExecutionState::class,
        ] as $id) {
            $builder->onResolved($id, static function (string $service) use ($tracker): void {
                $tracker->mark($service);
            });
        }


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
