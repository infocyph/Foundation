<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Filesystem\FilesystemManager;
use Infocyph\Foundation\Messaging\MessagingManager;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;

final readonly class TestKit
{
    public function __construct(private Application $application) {}

    public function auth(): AuthServices
    {
        return $this->application->auth();
    }

    public function cache(): CacheManager
    {
        return $this->application->cache();
    }

    public function database(): DatabaseTestManager
    {
        return new DatabaseTestManager($this->application->db());
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
        return $this->messaging()->fake();
    }

    public function fakeNotifications(): Emailer
    {
        $emailer = Emailer::fake();
        $this->application->container()->bind(Emailer::class, $emailer, LifetimeEnum::Singleton);

        return $emailer;
    }

    public function files(): FilesystemManager
    {
        return $this->application->files();
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

    public function messaging(): MessagingManager
    {
        return $this->application->messaging();
    }

    public function sessions(): SessionManager
    {
        return $this->application->session();
    }
}
