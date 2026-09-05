<?php

declare(strict_types=1);

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

it('boots without optional packages and reports unavailable capabilities cleanly', function (): void {
    $root = dirname(__DIR__, 2);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, $root . '/tests/Fixtures/OptionalCapabilityProbe.php'],
        new ProcessOptions(cwd: $root, timeoutSeconds: 30.0),
    );

    expect($result->successful())->toBeTrue()
        ->and(trim($result->stderr))->toBe('');

    $probe = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
    expect($probe)->toBeArray()
        ->and($probe)->not->toHaveKey('fatal')
        ->and($probe['base']['created_and_booted'] ?? false)->toBeTrue()
        ->and($probe['base']['base_path'] ?? null)->toBe($root)
        ->and($probe['warm_base_path'] ?? null)->toBe($root)
        ->and(array_filter($probe['loaded_before_isolation'] ?? []))->toBe([]);

    $services = $probe['services'] ?? [];
    expect($services)->toBeArray();
    foreach ($services as $service => $state) {
        expect($state)->toBeArray()
            ->and($state['has'] ?? true)->toBeFalse()
            ->and($state['message'] ?? null)->toBeString()
            ->and($state['message'])->toContain(
                'Unable to resolve service "' . $service . '".',
                "No entry found for '" . $service . "'.",
            );
    }

    expect($probe['auth']['default']['resolved'] ?? false)->toBeTrue()
        ->and($probe['auth']['default']['message'] ?? null)->toBeNull()
        ->and($probe['auth']['otp']['message'] ?? null)->toBeString()
        ->and($probe['auth']['otp']['message'])->toContain(
            'The selected auth driver requires infocyph/otp;',
            'module:install otp',
        )
        ->and($probe['auth']['webauthn']['message'] ?? null)->toBeString()
        ->and($probe['auth']['webauthn']['message'])->toContain(
            'The selected auth driver requires web-auth/webauthn-lib;',
            'module:install passkeys',
        );
});
