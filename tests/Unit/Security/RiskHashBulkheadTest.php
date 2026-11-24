<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;

it('requires provisioned slots and never releases a newer hash owner using a stale lease', function (): void {
    $cache = Cache::memory(namespace: 'risk-slots-' . bin2hex(random_bytes(8)), options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false));
    $authority = new RiskAuthority($cache, str_repeat('k', 32), shared: true);
    expect(fn() => new RiskHashBulkhead($authority, 'password-workers', 1))->toThrow(\LogicException::class);
    $bulkhead = new RiskHashBulkhead($authority, 'password-workers', 1, nonEvictingIsolatedPool: true);
    expect($bulkhead->acquire())->toBeNull()
        ->and($bulkhead->provisionDrainedPool())->toBeTrue();
    $old = $bulkhead->acquire();
    \PHPUnit\Framework\Assert::assertNotNull($old);
    expect($bulkhead->acquire())->toBeNull()
        ->and($bulkhead->provisionDrainedPool())->toBeFalse()
        ->and($bulkhead->release($old))->toBeTrue();
    $current = $bulkhead->acquire();
    \PHPUnit\Framework\Assert::assertNotNull($current);
    expect($bulkhead->release($old))->toBeFalse()
        ->and($bulkhead->acquire())->toBeNull()
        ->and($bulkhead->release($current))->toBeTrue();
    $cache->clear();
    expect($bulkhead->acquire())->toBeNull();
});
