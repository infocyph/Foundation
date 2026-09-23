<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\Testing\WebhookTestFactory;

it('keeps mutable TalkingBytes clients isolated across sequential Foundation execution scopes', function (): void {
    $app = foundationTalkingBytes21StatefulApplication();
    $container = $app->container();

    $first = $container->withinScope('webrick.request', static function () use ($app): array {
        $http = $app->make(HttpClient::class);
        $emailer = $app->make(Emailer::class);

        expect($app->make(HttpClient::class))->toBe($http)
            ->and($app->make(Emailer::class))->toBe($emailer);

        $emailer->send(
            EmailMessage::new()
                ->from('sender@example.test')
                ->to('first@example.test')
                ->subject('first')
                ->text('first'),
        );
        $emailer->assertable()->assertSentCount(1);

        return [spl_object_id($http), spl_object_id($emailer)];
    });

    $second = $container->withinScope('webrick.request', static function () use ($app): array {
        $http = $app->make(HttpClient::class);
        $emailer = $app->make(Emailer::class);

        $emailer->assertable()->assertNothingSent();

        return [spl_object_id($http), spl_object_id($emailer)];
    });

    expect($first[0])->not->toBe($second[0])
        ->and($first[1])->not->toBe($second[1]);
});

it('keeps scoped TalkingBytes client graphs Fiber-local under interleaving', function (): void {
    $app = foundationTalkingBytes21StatefulApplication();

    $run = static function () use ($app): Fiber {
        return new Fiber(static function () use ($app): array {
            return $app->container()->withinScope('webrick.request', static function () use ($app): array {
                $before = [
                    spl_object_id($app->make(HttpClient::class)),
                    spl_object_id($app->make(Emailer::class)),
                ];

                Fiber::suspend($before);

                return [
                    spl_object_id($app->make(HttpClient::class)),
                    spl_object_id($app->make(Emailer::class)),
                ];
            });
        });
    };

    $first = $run();
    $second = $run();
    $firstBefore = $first->start();
    $secondBefore = $second->start();

    expect($firstBefore[0])->not->toBe($secondBefore[0])
        ->and($firstBefore[1])->not->toBe($secondBefore[1]);

    $first->resume();
    $second->resume();

    expect($first->getReturn())->toBe($firstBefore)
        ->and($second->getReturn())->toBe($secondBefore);
});

it('uses TalkingBytes v2 bound webhook signatures with Foundation atomic replay state', function (): void {
    $secret = 'foundation-talkingbytes-21-secret';
    $app = Foundation::web([
        'base_path' => dirname(__DIR__, 2),
        '_config_cache' => false,
        'communication' => [
            'webhooks' => [
                'default_inbound' => 'default',
                'inbound' => [
                    'default' => [
                        'secret' => $secret,
                        'max_age_seconds' => 300,
                        'max_payload_bytes' => 1_048_576,
                        'replay' => [
                            'enabled' => true,
                            'ttl_seconds' => 60,
                            'namespace' => 'foundation-test',
                        ],
                    ],
                ],
            ],
        ],
    ])->boot();

    $cache = Cache::memory(
        namespace: 'foundation-talkingbytes-21-replay',
        options: new CacheOptions(failOpen: false),
    );
    $receiver = $app->make(CommunicationProfiles::class)->webhookReceiver(
        'default',
        new CacheLayerWebhookReplayStore($cache),
        60,
    );

    [$payload, $headers] = WebhookTestFactory::signedJson(
        $secret,
        'order.created',
        ['id' => 7],
        'foundation-delivery-7',
    );

    expect((string) ($headers['X-TB-Signature'] ?? ''))->toContain('v2=');

    $event = $receiver->receive($payload, $headers);
    expect($event->event)->toBe('order.created')
        ->and($event->deliveryId)->toBe('foundation-delivery-7');

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'already been processed');

    $tampered = $headers;
    $tampered['X-TB-Delivery'] = 'foundation-delivery-8';
    expect(fn() => $receiver->receive($payload, $tampered))
        ->toThrow(RuntimeException::class, 'signature_mismatch');
});

function foundationTalkingBytes21StatefulApplication(): \Infocyph\Foundation\Application\Application
{
    return Foundation::web([
        'base_path' => dirname(__DIR__, 2),
        '_config_cache' => false,
        'communication' => [
            'http' => [
                'default_client' => 'stateful',
                'clients' => [
                    'stateful' => [
                        'cookies' => ['enabled' => true],
                        'rate_limit' => [
                            'enabled' => true,
                            'max_requests' => 100,
                            'per_seconds' => 60,
                        ],
                        'circuit_breaker' => [
                            'enabled' => true,
                            'failure_threshold' => 3,
                            'cool_down_seconds' => 5,
                        ],
                    ],
                ],
            ],
        ],
        'notifications' => [
            'email' => [
                'default_sender' => 'default',
                'senders' => [
                    'default' => ['transport' => 'fake'],
                ],
                'transports' => [
                    'fake' => ['driver' => 'fake'],
                ],
            ],
        ],
    ])->boot();
}
