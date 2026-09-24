<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Session\SessionConfig;

it('rejects unusable session lock durations before contacting a backend', function (string $key, mixed $value): void {
    expect(fn() => SessionConfig::fromRepository(
        new ConfigRepository(['session' => ['lock' => [$key => $value]]]),
        sys_get_temp_dir(),
    ))->toThrow(InvalidArgumentException::class, 'session.lock.' . $key);
})->with(['wait', 'lease'])->with([INF, -INF, NAN, -1, '1', true]);

it('allows finite fractional durations and a nonblocking wait but rejects a zero lease', function (): void {
    $config = SessionConfig::fromRepository(new ConfigRepository([
        'session' => ['lock' => ['wait' => 0, 'lease' => 0.25]],
    ]), sys_get_temp_dir());

    expect($config->lockWaitSeconds)->toBe(0.0)
        ->and($config->lockLeaseSeconds)->toBe(0.25)
        ->and(fn() => SessionConfig::fromRepository(new ConfigRepository([
            'session' => ['lock' => ['lease' => 0]],
        ]), sys_get_temp_dir()))->toThrow(InvalidArgumentException::class, 'session.lock.lease');
});
