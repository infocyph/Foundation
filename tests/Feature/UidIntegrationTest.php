<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Foundation;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\UID\Runtime\GenerationContext;
use Infocyph\UID\Runtime\RunwireBinding;
use Infocyph\UID\Id;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

it('uses UID directly for general application identifiers', function (): void {
    $uuid = Id::uuid7();
    $ulid = Id::ulid();

    expect(UUID::isValid($uuid))->toBeTrue()
        ->and(ULID::isValid($ulid))->toBeTrue();
});

it('supports configured auth identifier strategies', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'ids' => 'uid',
            ],
        ],
        'ids' => [
            'auth' => [
                'account' => 'ulid',
                'correlation' => 'uuid7',
            ],
        ],
    ])->boot();

    $authIds = $app->make(AuthIdGeneratorInterface::class);

    expect($authIds)->toBeInstanceOf(UidAuthIdGenerator::class)
        ->and(ULID::isValid($authIds->accountId()))->toBeTrue()
        ->and(UUID::isValid($authIds->correlationId()))->toBeTrue();
});

it('uses UID-backed defaults for auth identifiers', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => [],
        ],
    ]);

    $ids = $app->make(AuthIdGeneratorInterface::class);

    expect($ids)->toBeInstanceOf(UidAuthIdGenerator::class)
        ->and(UUID::isValid($ids->accountId()))->toBeTrue()
        ->and(ULID::isValid($ids->correlationId()))->toBeTrue();
});

it('rejects unsupported auth identifier strategies instead of falling back silently', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => [],
        ],
        'ids' => [
            'auth' => [
                'account' => 'random',
            ],
        ],
    ]);

    $ids = $app->make(AuthIdGeneratorInterface::class);

    expect(fn(): string => $ids->accountId())
        ->toThrow(InvalidArgumentException::class, 'use uuid7 or ulid');
});

it('keeps UID 6 identifiers distinct and leaves UUID v2 opt-in', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => ['cache' => false, 'files' => []],
    ]);
    $ids = $app->make(AuthIdGeneratorInterface::class);

    $accounts = [];
    $correlations = [];
    for ($i = 0; $i < 64; ++$i) {
        $accounts[] = $ids->accountId();
        $correlations[] = $ids->correlationId();
    }

    expect(count(array_unique($accounts)))->toBe(64)
        ->and(count(array_unique($correlations)))->toBe(64)
        ->and(array_filter($accounts, static fn(string $id): bool => !UUID::isValid($id) || $id[14] !== '7'))->toBeEmpty()
        ->and(array_filter($correlations, static fn(string $id): bool => !ULID::isValid($id)))->toBeEmpty();

    $uuid2 = Id::uuid2(42);
    expect(UUID::isValid($uuid2))->toBeTrue()
        ->and($uuid2[14])->toBe('2')
        ->and($accounts)->not->toContain($uuid2);
});

it('generates Foundation auth IDs across Runwire coroutine work without retaining request state', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => ['cache' => false, 'files' => []],
    ]);
    $ids = $app->make(AuthIdGeneratorInterface::class);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $generation = new GenerationContext(runwire: new RunwireBinding($runtime, $request));

    try {
        $results = (new CoroutineRuntime())->run(
            static function (CoroutineScope $scope) use ($ids, $generation): array {
                $tasks = [];
                for ($i = 0; $i < 16; ++$i) {
                    $tasks[] = $scope->spawn(
                        static fn(): array => [
                            $ids->accountId(),
                            $ids->correlationId(),
                            $generation->nowMilliseconds(),
                        ],
                    );
                }

                return array_map(static fn($task): array => $task->await(), $tasks);
            },
        );

        expect(count(array_unique(array_column($results, 0))))->toBe(16)
            ->and(count(array_unique(array_column($results, 1))))->toBe(16)
            ->and($results[0][2])->toBeGreaterThan(0);
        foreach ($results as [$account, $correlation]) {
            expect(UUID::isValid($account))->toBeTrue()
                ->and(ULID::isValid($correlation))->toBeTrue();
        }

        $request->cancel(CancellationReason::HOST_CANCELLED);
        expect(fn(): int => $generation->nowMilliseconds())->toThrow(CancelledException::class);
    } finally {
        $request->complete();
    }
});
