<?php

declare(strict_types=1);

use Infocyph\Foundation\Runtime\CleanupGuard;

it('attempts every cleanup while preserving an existing primary failure', function (): void {
    $primary = new RuntimeException('primary');
    $calls = [];

    CleanupGuard::run(
        $primary,
        static function () use (&$calls): void {
            $calls[] = 'first';
            throw new LogicException('cleanup');
        },
        static function () use (&$calls): void {
            $calls[] = 'second';
        },
    );

    expect($calls)->toBe(['first', 'second']);
});

it('throws the first cleanup failure only when no primary failure exists', function (): void {
    $calls = [];

    expect(fn() => CleanupGuard::run(
        null,
        static function () use (&$calls): void {
            $calls[] = 'first';
            throw new LogicException('first cleanup');
        },
        static function () use (&$calls): void {
            $calls[] = 'second';
            throw new RuntimeException('second cleanup');
        },
    ))->toThrow(LogicException::class, 'first cleanup');

    expect($calls)->toBe(['first', 'second']);
});
