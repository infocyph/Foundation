<?php

declare(strict_types=1);

use Infocyph\Foundation\Module\Internal\ModuleCatalogValidator;
use Infocyph\Foundation\Module\ModuleCatalog;

it('models managed feature and optional package roles without broadening installs', function (): void {
    $catalog = new ModuleCatalog();
    $catalog->validate();
    $modules = $catalog->all();

    expect($modules['auth']['packages']['infocyph/otp']['role'] ?? null)->toBe('feature')
        ->and($modules['auth']['packages']['infocyph/otp']['features'] ?? null)->toBe(['otp', 'passkey'])
        ->and($modules['auth']['packages']['web-auth/webauthn-lib']['features'] ?? null)->toBe(['passkey'])
        ->and($catalog->managedPackages($modules['auth']))->toBe([
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ])
        ->and($modules['communication']['packages']['grpc/grpc']['role'] ?? null)->toBe('optional')
        ->and($catalog->managedPackages($modules['communication']))->toBe([
            'infocyph/talkingbytes' => '^2.3',
        ])
        ->and($modules['messaging']['packages'])->not->toHaveKey('infocyph/runwire')
        ->and($catalog->managedPackages($modules['messaging']))->toBe([
            'infocyph/omnibus' => '^3.0.1',
        ]);
});

it('documents the canonical three-module feature IDs and package version floors', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/docs/modules.md');
    expect($content)->toBeString();

    $catalog = new \Infocyph\Foundation\Module\ModuleArchitectureCatalog();
    foreach ($catalog->all() as $group => $definition) {
        foreach ($definition['features'] as $feature => $metadata) {
            expect($content)->toContain('`' . $group . '.' . $feature . '`');
            foreach ($metadata['packages'] as $constraint) {
                expect($content)->toContain($constraint);
            }
        }
    }
});

it('keeps Composer core and specialist module floors on one supported generation', function (): void {
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    expect($manifest)->toBeArray();

    $core = [
        'infocyph/arraykit' => '^5.3.1',
        'infocyph/cachelayer' => '^4.0.1',
        'infocyph/intermix' => '^11.2',
        'infocyph/runwire' => '^2.1.1',
        'infocyph/uid' => '^6.0',
        'infocyph/webrick' => '^6.0.3',
    ];
    foreach ($core as $package => $constraint) {
        expect($manifest['require'][$package] ?? null)->toBe($constraint);
    }

    $catalog = new ModuleCatalog();
    foreach ($catalog->all() as $module) {
        foreach ($catalog->managedPackages($module) as $package => $constraint) {
            expect($manifest['require-dev'][$package] ?? $manifest['require'][$package] ?? null)
                ->toBe($constraint);
        }
    }
});

it('keeps all publishable module templates aligned with the configuration guide', function (): void {
    $root = dirname(__DIR__, 2);
    $documentation = file_get_contents($root . '/docs/configuration.md');
    expect($documentation)->toBeString();

    foreach (new ModuleCatalog()->all() as $module) {
        foreach ($module['config'] as $filename) {
            expect($root . '/resources/config/' . $filename)->toBeFile()
                ->and($documentation)->toContain('`resources/config/' . $filename . '`');
        }
    }
});

it('pins the complete seven-specialist module contract', function (): void {
    $catalog = new ModuleCatalog();
    $modules = $catalog->all();
    $specialists = array_filter(
        $modules,
        static fn(array $definition): bool => ($definition['built_in'] ?? false) !== true,
    );

    expect(array_keys($specialists))->toBe([
        'auth',
        'communication',
        'database',
        'filesystem',
        'messaging',
        'security',
        'validation',
    ])->and(array_map(
        static fn(array $definition): array => $catalog->requiredPackages($definition),
        $specialists,
    ))->toBe([
        'auth' => [],
        'communication' => ['infocyph/talkingbytes' => '^2.3'],
        'database' => ['infocyph/dblayer' => '^6.1.1'],
        'filesystem' => ['infocyph/pathwise' => '^4.2'],
        'messaging' => ['infocyph/omnibus' => '^3.0.1'],
        'security' => ['infocyph/epicrypt' => '^3.2'],
        'validation' => ['infocyph/reqshield' => '^3.3'],
    ])->and(array_map(
        static fn(array $definition): array => $definition['config'],
        $specialists,
    ))->toBe([
        'auth' => [],
        'communication' => ['communication.php'],
        'database' => ['database.php'],
        'filesystem' => ['filesystem.php'],
        'messaging' => ['messaging.php'],
        'security' => ['security.php'],
        'validation' => ['validation.php'],
    ]);
});

it('resolves auth feature aliases without broadening the core module request', function (): void {
    $catalog = new ModuleCatalog();
    $auth = $catalog->resolve('auth');
    $otp = $catalog->resolve('mfa');
    $passkey = $catalog->resolve('webauthn');
    $webAuthnPackage = $catalog->resolve('web-auth/webauthn-lib');

    expect($auth['requested_features'])->toBe([])
        ->and($catalog->requiredPackages($auth))->toBe([])
        ->and($otp['name'])->toBe('auth')
        ->and($otp['requested_features'])->toBe(['otp'])
        ->and($passkey['requested_features'])->toBe(['passkey'])
        ->and($webAuthnPackage['requested_features'])->toBe(['passkey'])
        ->and($catalog->installationPackages($auth, ['otp']))->toBe([
            'infocyph/otp' => '^7.0',
        ])
        ->and($catalog->installationPackages($auth, ['passkey']))->toBe([
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ])
        ->and($catalog->resolve('infocyph/otp', ['otp'])['requested_features'])->toBe(['otp'])
        ->and($catalog->resolve('infocyph/otp', ['passkey'])['requested_features'])->toBe(['passkey'])
        ->and(fn() => $catalog->resolve('infocyph/otp'))
        ->toThrow(InvalidArgumentException::class, 'shared by features otp, passkey');
});

it('keeps notifications outside the specialist communication module vocabulary', function (): void {
    $catalog = new ModuleCatalog();
    $communication = $catalog->resolve('communication');

    expect($communication['aliases'])->toBe(['talkingbytes'])
        ->and($communication['config'])->toBe(['communication.php'])
        ->and(fn() => $catalog->resolve('notifications'))
        ->toThrow(InvalidArgumentException::class, 'Unknown module or feature "notifications".');
});

it('records platform requirements and conditional module dependencies declaratively', function (): void {
    $modules = (new ModuleCatalog())->all();

    expect($modules['communication']['platform']['extensions'] ?? null)
        ->toBe(['curl', 'fileinfo', 'openssl'])
        ->and($modules['database']['platform']['extensions'] ?? null)->toBe(['pdo'])
        ->and($modules['messaging']['platform']['extensions'] ?? null)->toBe([])
        ->and($modules['messaging']['platform']['optional_extensions'] ?? null)
        ->toBe(['memcached', 'msgpack', 'pcntl', 'posix', 'redis'])
        ->and($modules['security']['platform']['extensions'] ?? null)
        ->toBe(['hash', 'json', 'openssl', 'sodium'])
        ->and($modules['validation']['platform']['extensions'] ?? null)
        ->toBe(['fileinfo', 'hash', 'mbstring'])
        ->and($modules['messaging']['dependencies'][0]['target'] ?? null)->toBe('database')
        ->and($modules['messaging']['dependencies'][0]['when']['key'] ?? null)
        ->toBe('messaging.durable.enabled')
        ->and($modules['validation']['dependencies'][0]['target'] ?? null)->toBe('database')
        ->and($modules['validation']['dependencies'][0]['when']['operator'] ?? null)->toBe('not-empty')
        ->and($modules['auth']['dependencies'][3]['target'] ?? null)->toBe('communication')
        ->and($modules['auth']['dependencies'][4]['type'] ?? null)->toBe('capability')
        ->and($modules['auth']['dependencies'][4]['target'] ?? null)->toBe('notifications')
        ->and($modules['auth']['dependencies'][4]['when']['value'] ?? null)->toBe('talkingbytes');
});

it('rejects catalog identifier collisions and dependency cycles', function (): void {
    $catalog = new ModuleCatalog();
    $validator = new ModuleCatalogValidator();

    $aliases = $catalog->all();
    $aliases['database']['aliases'][] = 'queue';
    expect(fn() => $validator->validate($aliases))
        ->toThrow(LogicException::class, 'Module identifier "queue" is shared');

    $cycle = $catalog->all();
    $cycle['database']['dependencies'][] = [
        'type' => 'module',
        'target' => 'messaging',
        'reason' => 'Synthetic cycle fixture.',
    ];
    expect(fn() => $validator->validate($cycle))
        ->toThrow(LogicException::class, 'Module dependency graph contains a cycle');
});
