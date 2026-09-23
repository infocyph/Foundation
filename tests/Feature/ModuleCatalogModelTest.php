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
            'infocyph/otp' => '^6.1',
            'web-auth/webauthn-lib' => '^5.3.5',
        ])
        ->and($modules['communication']['packages']['grpc/grpc']['role'] ?? null)->toBe('optional')
        ->and($catalog->managedPackages($modules['communication']))->toBe([
            'infocyph/talkingbytes' => '^2.1',
        ])
        ->and($modules['messaging']['packages']['infocyph/runwire']['role'] ?? null)->toBe('optional')
        ->and($catalog->managedPackages($modules['messaging']))->toBe([
            'infocyph/omnibus' => '^2.6',
        ]);
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
        'communication' => ['infocyph/talkingbytes' => '^2.1'],
        'database' => ['infocyph/dblayer' => '^5.1'],
        'filesystem' => ['infocyph/pathwise' => '^4.1'],
        'messaging' => ['infocyph/omnibus' => '^2.6'],
        'security' => ['infocyph/epicrypt' => '^3.1'],
        'validation' => ['infocyph/reqshield' => '^3.2'],
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
            'infocyph/otp' => '^6.1',
        ])
        ->and($catalog->installationPackages($auth, ['passkey']))->toBe([
            'infocyph/otp' => '^6.1',
            'web-auth/webauthn-lib' => '^5.3.5',
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
        ->and($modules['messaging']['platform']['extensions'] ?? null)->toBe(['pcntl', 'posix'])
        ->and($modules['security']['platform']['extensions'] ?? null)
        ->toBe(['hash', 'json', 'openssl', 'sodium'])
        ->and($modules['validation']['platform']['extensions'] ?? null)
        ->toBe(['fileinfo', 'hash', 'mbstring'])
        ->and($modules['messaging']['dependencies'][0]['target'] ?? null)->toBe('database')
        ->and($modules['messaging']['dependencies'][0]['when']['key'] ?? null)
        ->toBe('messaging.durable.enabled')
        ->and($modules['validation']['dependencies'][0]['target'] ?? null)->toBe('database')
        ->and($modules['validation']['dependencies'][0]['when']['operator'] ?? null)->toBe('not-empty');
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
