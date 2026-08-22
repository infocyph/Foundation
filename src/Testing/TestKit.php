<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DatabaseMigrationManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use League\Flysystem\FilesystemOperator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

final readonly class TestKit
{
    public function __construct(private Application $application) {}

    public function auth(): AuthServices
    {
        return $this->application->auth();
    }

    public function cache(): CacheManager
    {
        return $this->application->make(CacheManager::class);
    }

    public function database(): DatabaseTestManager
    {
        return new DatabaseTestManager(
            $this->application->make(DBLayerFactory::class),
            $this->application->make(DatabaseMigrationManager::class),
        );
    }

    public function fakeCache(?CacheInterface $store = null, ?string $name = null): CacheInterface
    {
        return $this->cache()->useStore(
            $store ?? Cache::memory('foundation-test'),
            $name,
        );
    }

    public function fakeHttp(?FakeHttpTransport $transport = null): HttpClient
    {
        $client = HttpClient::fake($transport);
        $this->application->container()->bind(HttpClient::class, $client, LifetimeEnum::Singleton);

        return $client;
    }

    public function fakeMessaging(): RecordingSender
    {
        $sender = new RecordingSender();
        $transports = [];
        foreach ($this->messagingTransports() as $name) {
            $transports[$name] = $sender;
        }

        $registry = new TransportRegistry($transports);
        $bus = new MessageBus($this->application->make(RouteMap::class), $registry);
        $listeners = $this->application->make(ListenerProviderInterface::class);
        $events = new EventDispatcher($listeners, $bus);
        $container = $this->application->container();

        $container->bind(TransportRegistry::class, $registry, LifetimeEnum::Singleton);
        $container->bind(MessageBus::class, $bus, LifetimeEnum::Singleton);
        $container->bind(EventDispatcher::class, $events, LifetimeEnum::Singleton);
        $container->bind(EventDispatcherInterface::class, $events, LifetimeEnum::Singleton);

        return $sender;
    }

    public function fakeNotifications(): Emailer
    {
        $emailer = Emailer::fake();
        $this->application->container()->bind(Emailer::class, $emailer, LifetimeEnum::Singleton);

        return $emailer;
    }

    public function files(): FilesystemOperator
    {
        return $this->application->make(FilesystemOperator::class);
    }

    public function freezeTime(?int $timestamp = null): FrozenClock
    {
        $clock = new FrozenClock($timestamp ?? time());
        $this->application->container()->bind(ClockInterface::class, $clock, LifetimeEnum::Singleton);

        return $clock;
    }

    public function http(): HttpTestClient
    {
        return new HttpTestClient($this->application);
    }

    public function messaging(): MessageBus
    {
        return $this->application->make(MessageBus::class);
    }

    public function sessions(): SessionManager
    {
        return $this->application->session();
    }

    /** @return list<string> */
    private function messagingTransports(): array
    {
        $configured = $this->application->config()->get('messaging', []);
        $configured = is_array($configured) ? $configured : [];
        $names = ['sync' => true, 'memory' => true];

        $default = $configured['default_route'] ?? null;
        if (is_array($default) && is_string($default['transport'] ?? null) && $default['transport'] !== '') {
            $names[$default['transport']] = true;
        }

        $routes = $configured['routes'] ?? null;
        if (is_array($routes)) {
            foreach ($routes as $route) {
                if (is_array($route) && is_string($route['transport'] ?? null) && $route['transport'] !== '') {
                    $names[$route['transport']] = true;
                }
            }
        }

        return array_keys($names);
    }
}
