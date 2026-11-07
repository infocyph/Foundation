<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\UID\Id;

it('keeps Foundation auth and execution IDs on UID 6 defaults and configured formats', function (): void {
    $ids = new UidAuthIdGenerator(new ConfigRepository());
    $uuid7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    $ulid = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    foreach ([
        $ids->accountId(),
        $ids->auditEventId(),
        $ids->challengeId(),
        $ids->credentialId(),
        $ids->deviceId(),
        $ids->permissionId(),
        $ids->roleId(),
        $ids->sessionId(),
    ] as $id) {
        expect($id)->toMatch($uuid7);
    }

    expect($ids->correlationId())->toMatch($ulid)
        ->and((string) ExecutionId::generate())->toMatch($ulid);

    $custom = new UidAuthIdGenerator(new ConfigRepository([
        'ids' => ['auth' => [
            'account' => 'ulid',
            'correlation' => 'uuid7',
        ]],
    ]));
    expect($custom->accountId())->toMatch($ulid)
        ->and($custom->correlationId())->toMatch($uuid7)
        ->and(fn() => (new UidAuthIdGenerator(new ConfigRepository([
            'ids' => ['auth' => ['account' => 'uuid2']],
        ])))->accountId())->toThrow(InvalidArgumentException::class, 'Unsupported auth ID driver');

    // Native UID 6 UUIDv2 is public but never silently becomes a
    // Foundation account/execution default.
    expect(Id::uuid2(42))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-2[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('preserves unique UID 6 generation across interleaved Fibers and borrowed Runwire worker context', function (): void {
    $app = Foundation::worker([
        'app' => [
            'env' => 'testing',
            'capabilities' => ['runwire' => true],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $ids = new UidAuthIdGenerator(new ConfigRepository());
    $fibers = [];
    $observed = [];

    try {
        for ($i = 0; $i < 8; $i++) {
            $fibers[] = new Fiber(static function () use ($app, $ids, $runtime, $request): array {
                return $app->execution()->run(
                    static function () use ($ids): array {
                        $first = [];
                        for ($n = 0; $n < 16; $n++) {
                            $first[] = $ids->accountId();
                        }

                        Fiber::suspend($first);

                        $last = [];
                        for ($n = 0; $n < 16; $n++) {
                            $last[] = $ids->accountId();
                        }

                        return $last;
                    },
                    seeds: [
                        RuntimeContext::class => $runtime,
                        RequestContext::class => $request,
                    ],
                );
            });
        }

        foreach ($fibers as $fiber) {
            $part = $fiber->start();
            expect($part)->toHaveCount(16);
            array_push($observed, ...$part);
        }

        foreach ($fibers as $fiber) {
            $fiber->resume();
            $part = $fiber->getReturn();
            expect($part)->toHaveCount(16);
            array_push($observed, ...$part);
        }

        expect($observed)->toHaveCount(256)
            ->and(array_unique($observed))->toHaveCount(256);
        foreach ($observed as $value) {
            expect($value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
        }
    } finally {
        $request->complete();
    }
});

it('keeps native UID generation isolated across sequential executions on a reused Foundation worker', function (): void {
    $app = Foundation::worker([
        'app' => [
            'env' => 'testing',
            'capabilities' => ['runwire' => true],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $ids = new UidAuthIdGenerator(new ConfigRepository());
    $accounts = [];
    $correlations = [];
    $executions = [];

    for ($cycle = 0; $cycle < 16; ++$cycle) {
        $request = RequestContext::create($runtime);
        try {
            [$account, $correlation, $execution] = $app->execution()->run(
                static fn(): array => [
                    $ids->accountId(),
                    $ids->correlationId(),
                    (string) ExecutionId::generate(),
                ],
                seeds: [
                    RuntimeContext::class => $runtime,
                    RequestContext::class => $request,
                ],
            );

            $accounts[] = $account;
            $correlations[] = $correlation;
            $executions[] = $execution;
        } finally {
            $request->complete();
        }
    }

    expect($accounts)->toHaveCount(16)
        ->and(array_unique($accounts))->toHaveCount(16)
        ->and(array_unique($correlations))->toHaveCount(16)
        ->and(array_unique($executions))->toHaveCount(16);

    foreach ($accounts as $account) {
        expect($account)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }
    foreach ([...$correlations, ...$executions] as $value) {
        expect($value)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
});
