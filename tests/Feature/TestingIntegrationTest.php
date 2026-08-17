<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Filesystem\FilesystemManager;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;

it('composes package fakes without replacing their implementations', function (): void {
    $app = Foundation::cli([
        'cache' => [
            'default' => 'array',
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ],
    ]);
    $transport = new FakeHttpTransport();

    $cache = $app->testing()->fakeCache();
    $http = $app->testing()->fakeHttp($transport);
    $notifications = $app->testing()->fakeNotifications();
    $clock = $app->testing()->freezeTime(1_700_000_000);

    $cache->set('answer', 42);
    $transport->pushJson(['ok' => true]);
    $result = $http->get('https://example.test/health');
    $http->assert()->assertRequestCount(1);
    $notifications->assertable()->assertNothingSent();

    expect($cache)->toBeInstanceOf(CacheInterface::class)
        ->and($app->testing()->cache()->store())->toBe($cache)
        ->and($cache->get('answer'))->toBe(42)
        ->and($app->make(HttpClient::class))->toBe($http)
        ->and($result->successful)->toBeTrue()
        ->and($app->make(Emailer::class))->toBe($notifications)
        ->and($app->make(ClockInterface::class))->toBe($clock)
        ->and($clock->advance(60)->now())->toBe(1_700_000_060);
});

it('exposes the configured filesystem through the testing boundary', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-files-' . bin2hex(random_bytes(6));
    $app = Foundation::cli([
        'app' => ['base_path' => $basePath],
    ]);

    expect($app->testing()->files())->toBeInstanceOf(FilesystemManager::class)
        ->and($app->testing()->files())->toBe($app->make(FilesystemManager::class))
        ->and($app->testing()->files()->base())->toBe($basePath);
});
