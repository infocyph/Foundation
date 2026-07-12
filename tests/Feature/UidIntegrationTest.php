<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Facades\Ids;
use Infocyph\Foundation\Foundation;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

it('exposes uid algorithms and parsing through foundation ids manager', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-ids-' . uniqid('', true);
    mkdir($basePath . '/cache', 0775, true);

    $app = Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'paths' => [
            'cache' => 'cache',
        ],
        'ids' => [
            'default' => 'nanoid',
            'nanoid' => [
                'length' => 16,
            ],
            'deterministic' => [
                'length' => 12,
                'namespace' => 'infbyte',
            ],
        ],
    ])->boot();

    $nanoId = $app->ids()->generate();
    $uuid = $app->ids()->uuid7();
    $ulid = Ids::ulid();

    expect($nanoId)->toHaveLength(16)
        ->and($app->ids()->isValid('nanoid', (string) $nanoId))->toBeTrue()
        ->and(UUID::isValid($uuid))->toBeTrue()
        ->and(ULID::isValid($ulid))->toBeTrue()
        ->and($app->ids()->deterministic('invoice:42'))->toBe($app->ids()->deterministic('invoice:42'))
        ->and($app->ids()->parse('ulid', $ulid))->toHaveKey('time');

    $sorted = $app->ids()->sort([
        UUID::max(),
        UUID::nil(),
        $uuid,
    ]);

    expect($sorted[0])->toBe(UUID::nil())
        ->and($sorted[2])->toBe(UUID::max());
});

it('supports configured sequence-backed generators and auth id strategies', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-ids-auth-' . uniqid('', true);
    mkdir($basePath . '/cache', 0775, true);

    $app = Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'paths' => [
            'cache' => 'cache',
        ],
        'ids' => [
            'default' => 'snowflake',
            'sequence' => [
                'driver' => 'filesystem',
                'directory' => 'cache/ids-seq',
            ],
            'nanoid' => [
                'length' => 18,
            ],
            'snowflake' => [
                'datacenter_id' => 4,
                'worker_id' => 2,
                'output' => 'string',
            ],
            'tbsl' => [
                'machine_id' => 7,
                'sequenced' => true,
                'output' => 'string',
            ],
            'auth' => [
                'account' => 'ulid',
                'correlation' => 'nanoid',
            ],
        ],
    ])->boot();

    $snowflake = (string) $app->ids()->generate();
    $snowflakeParts = $app->ids()->parse('snowflake', $snowflake);
    $tbsl = (string) $app->ids()->tbsl();
    $tbslParts = $app->ids()->parse('tbsl', $tbsl);
    $authIds = $app->make(AuthIdGeneratorInterface::class);

    expect($snowflakeParts['datacenter_id'])->toBe(4)
        ->and($snowflakeParts['worker_id'])->toBe(2)
        ->and($tbslParts['machineId'])->toBe(7)
        ->and(ULID::isValid($authIds->accountId()))->toBeTrue()
        ->and(strlen($authIds->correlationId()))->toBe(18)
        ->and($basePath . '/cache/ids-seq')->toBeDirectory();
});
