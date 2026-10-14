<?php

declare(strict_types=1);

use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Module\ModuleCatalog;

it('pins the approved Foundation 4 optional modules and nine feature IDs', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $catalog->validate();

    expect(array_keys($catalog->all()))->toBe(['data', 'security', 'execution'])
        ->and(array_keys($catalog->all()['data']['features']))->toBe(['database', 'filesystem'])
        ->and(array_keys($catalog->all()['security']['features']))->toBe(['crypto', 'validation', 'mfa', 'passkey'])
        ->and(array_keys($catalog->all()['execution']['features']))->toBe(['runtime', 'messaging', 'communication'])
        ->and(array_sum(array_map(
            static fn(array $module): int => count($module['features']),
            $catalog->all(),
        )))->toBe(9);
});

it('installs selected feature packages only, with explicit OTP passkey co-ownership', function (): void {
    $catalog = new ModuleArchitectureCatalog();

    expect($catalog->packages('data', []))->toBe([])
        ->and($catalog->packages('data', ['filesystem']))->toBe(['infocyph/pathwise' => '^4.2'])
        ->and($catalog->packages('security', ['crypto']))->toBe(['infocyph/epicrypt' => '^3.2'])
        ->and($catalog->packages('security', ['passkey']))->toBe([
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ])
        ->and($catalog->packages('security', ['mfa']))->toBe(['infocyph/otp' => '^7.0'])
        ->and($catalog->packages('security', ['mfa', 'passkey']))->toBe([
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ])
        ->and($catalog->packages('execution', ['messaging']))->toBe(['infocyph/omnibus' => '^3.0'])
        ->and($catalog->packages('execution', ['runtime']))->toBe(['infocyph/runwire' => '^2.1.1'])
        ->and($catalog->packages('execution', ['communication']))->toBe(['infocyph/talkingbytes' => '^2.3']);
});

it('does not make core, PHPForge, or external developer packages installable modules', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest)->toBeArray();

    $owned = [];
    foreach ($catalog->all() as $module) {
        foreach ($module['features'] as $feature) {
            foreach ($feature['packages'] as $package => $constraint) {
                $owned[$package] = $constraint;
                expect($manifest['require-dev'][$package] ?? null)->toBe($constraint);
                expect($manifest['require'][$package] ?? null)->toBeNull();
            }
        }
    }

    expect($owned)->not->toHaveKey('infocyph/phpforge')
        ->and($owned)->not->toHaveKey('infocyph/cachelayer')
        ->and($owned)->not->toHaveKey('psr/log')
        ->and($owned)->not->toHaveKey('opis/closure')
        ->and($manifest['require']['psr/log'] ?? null)->toBe('^3.0.2')
        ->and($manifest['require-dev']['infocyph/phpforge'] ?? null)->toBe('dev-main@dev');
});

it('keeps selected module feature ownership separate from core schema and native host ownership', function (): void {
    $catalog = new ModuleArchitectureCatalog();

    expect($catalog->feature('execution', 'messaging')['schemas'])->toBe(['messaging'])
        ->and($catalog->feature('security', 'mfa')['selection_key'])->toBe('auth.drivers.mfa')
        ->and($catalog->feature('security', 'passkey')['selection_key'])->toBe('auth.drivers.passkey')
        ->and($catalog->feature('security', 'passkey')['dependencies'])->toBe([])
        ->and($catalog->feature('execution', 'runtime')['owner'])->toBe('infocyph/runwire')
        ->and($catalog->feature('execution', 'messaging')['owner'])->toBe('infocyph/omnibus')
        ->and($catalog->feature('execution', 'messaging')['dependencies'][0]['target'])->toBe('data.database')
        ->and($catalog->feature('data', 'database')['schemas'])->toBe([])
        ->and(fn() => $catalog->feature('core', 'cache'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->feature('execution', 'unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->packages('qa', []))->toThrow(InvalidArgumentException::class);
});

it('rejects foreign modules, package ownership collisions, undeclared dependencies and cycles', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $modules = $catalog->all();
    $extra = $modules;
    $extra['qa'] = $modules['data'];
    expect(fn() => $catalog->validate($extra))->toThrow(LogicException::class);

    $shared = $modules;
    $shared['execution']['features']['messaging']['packages']['infocyph/pathwise'] = '^4.2';
    expect(fn() => $catalog->validate($shared))->toThrow(LogicException::class, 'multiple unrelated feature owners');

    $unknown = $modules;
    $unknown['data']['features']['database']['dependencies'][] = ['target' => 'unknown.feature'];
    expect(fn() => $catalog->validate($unknown))->toThrow(LogicException::class, 'Unknown or self-referential');

    $cycle = $modules;
    $cycle['data']['features']['database']['dependencies'][] = ['target' => 'execution.messaging'];
    expect(fn() => $catalog->validate($cycle))->toThrow(LogicException::class, 'Cyclic optional feature dependencies');

    $core = $modules;
    $core['execution']['features']['messaging']['packages']['infocyph/cachelayer'] = '^4.0';
    expect(fn() => $catalog->validate($core))->toThrow(LogicException::class, 'Invalid optional package owner');
});

it('resolves only qualified canonical feature IDs without ambiguous old aliases', function (): void {
    $catalog = new ModuleArchitectureCatalog();

    expect($catalog->resolve('data.database')['definition']['owner'])->toBe('infocyph/dblayer')
        ->and($catalog->resolve('execution.runtime')['definition']['owner'])->toBe('infocyph/runwire')
        ->and(fn() => $catalog->resolve('runtime'))->toThrow(InvalidArgumentException::class, 'module.feature')
        ->and(fn() => $catalog->resolve('operations'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->resolve('security.unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->resolve('data.database.extra'))->toThrow(InvalidArgumentException::class);
});

it('exposes the approved catalog via ModuleCatalog without changing existing runtime calls before cutover', function (): void {
    $current = new ModuleCatalog();
    $target = new ModuleArchitectureCatalog();

    expect($current->architecture())->toBe($target->all());
});
