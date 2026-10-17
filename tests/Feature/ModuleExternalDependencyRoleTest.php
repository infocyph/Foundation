<?php

declare(strict_types=1);

use Infocyph\Foundation\Module\ModuleArchitectureCatalog;

it('keeps external runtime and developer requirements in their correct Composer roles', function (): void {
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $runtime = $manifest['require'];
    $development = $manifest['require-dev'];
    $features = new ModuleArchitectureCatalog();

    expect($runtime['psr/log'] ?? null)->toBe('^3.0.2')
        ->and($runtime['infocyph/runwire'] ?? null)->toBe('^2.1.2')
        ->and($development)->not->toHaveKey('infocyph/runwire')
        ->and($manifest['suggest'])->not->toHaveKey('infocyph/runwire')
        ->and($development['opis/closure'] ?? null)->toBe('^4.5')
        ->and($runtime)->not->toHaveKey('opis/closure')
        ->and($runtime)->not->toHaveKey('web-auth/webauthn-lib')
        ->and($runtime)->not->toHaveKey('infocyph/phpforge')
        ->and($development['web-auth/webauthn-lib'] ?? null)->toBe('^5.3.9')
        ->and($features->feature('security', 'passkey')['packages'])->toBe([
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ])
        ->and($features->feature('security', 'mfa')['packages'])->toBe(['infocyph/otp' => '^7.0']);
});

it('pins required feature extensions without making optional accelerators Core dependencies', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($catalog->feature('data', 'database')['platform']['extensions'])->toBe(['pdo'])
        ->and($catalog->feature('data', 'filesystem')['platform']['extensions'])->toBe(['fileinfo'])
        ->and($catalog->feature('security', 'validation')['platform']['extensions'])->toBe(['fileinfo', 'hash', 'mbstring'])
        ->and($catalog->feature('security', 'mfa')['platform']['extensions'])->toBe(['ctype'])
        ->and($catalog->feature('security', 'passkey')['platform']['optional_extensions'])->toContain('sodium')
        ->and($manifest['require']['infocyph/runwire'])->toBe('^2.1.2')
        ->and($manifest['require'])->not->toHaveKey('ext-sodium')
        ->and($manifest['require'])->not->toHaveKey('ext-pcntl')
        ->and($manifest['require'])->not->toHaveKey('ext-posix');
});
