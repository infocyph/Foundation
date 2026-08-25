<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Transport\InMemoryTransport;

final readonly class FoundationOmnibus25FailureMessage
{
    public function __construct(public string $value) {}
}

it('preserves Omnibus 2.5 failure retry claim and removal semantics used by queue retry', function (): void {
    $clock = new SystemClock();
    $failures = new InMemoryFailureStore($clock);
    $transport = new InMemoryTransport($clock);
    $manager = new FailureManager($failures);
    $failure = FailedMessage::decoded(
        'foundation-failure-1',
        'failed',
        Envelope::wrap(new FoundationOmnibus25FailureMessage('retry-me')),
        1,
        new DateTimeImmutable('-1 minute'),
        RuntimeException::class,
        'expected failure',
    );
    $failures->add($failure);

    $sent = $manager->retry('foundation-failure-1', $transport, 'retry');

    expect($sent->message)->toBeInstanceOf(FoundationOmnibus25FailureMessage::class)
        ->and($sent->message->value)->toBe('retry-me')
        ->and($transport->size('retry'))->toBe(1)
        ->and($failures->find('foundation-failure-1'))->toBeNull();
});

it('preserves Omnibus 2.5 failure list, prune, forget and flush semantics', function (): void {
    $clock = new SystemClock();
    $failures = new InMemoryFailureStore($clock);
    $manager = new FailureManager($failures);

    $failures->add(FailedMessage::decoded(
        'foundation-failure-old',
        'failed',
        Envelope::wrap(new FoundationOmnibus25FailureMessage('old')),
        1,
        new DateTimeImmutable('-2 days'),
        RuntimeException::class,
        'old failure',
    ));
    $failures->add(FailedMessage::decoded(
        'foundation-failure-current',
        'failed',
        Envelope::wrap(new FoundationOmnibus25FailureMessage('current')),
        2,
        new DateTimeImmutable('now'),
        RuntimeException::class,
        'current failure',
    ));

    expect($failures->all())->toHaveCount(2)
        ->and($manager->prune(new DateTimeImmutable('-1 day')))->toBe(1)
        ->and($failures->find('foundation-failure-old'))->toBeNull()
        ->and($manager->forget('foundation-failure-current'))->toBeTrue()
        ->and($failures->all())->toBe([]);

    $failures->add(FailedMessage::decoded(
        'foundation-failure-a',
        'failed',
        Envelope::wrap(new FoundationOmnibus25FailureMessage('a')),
        1,
        new DateTimeImmutable('now'),
        RuntimeException::class,
        'a',
    ));
    $failures->add(FailedMessage::decoded(
        'foundation-failure-b',
        'failed',
        Envelope::wrap(new FoundationOmnibus25FailureMessage('b')),
        1,
        new DateTimeImmutable('now'),
        RuntimeException::class,
        'b',
    ));

    expect($manager->flush())->toBe(2)
        ->and($failures->all())->toBe([]);
});
