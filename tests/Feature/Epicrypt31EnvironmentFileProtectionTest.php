<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Exception\Crypto\DecryptionException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Security\EnvironmentFileProtector;

it('round trips protected environment files and preserves an existing target on failure', function (): void {
    $root = sys_get_temp_dir() . '/foundation-env-protection-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $environment = 'FOUNDATION_TEST_ENV_FILE_KEY';
    $key = (new KeyMaterialGenerator())->forSecretStream();
    $_ENV[$environment] = $key;
    $_SERVER[$environment] = $key;
    putenv($environment . '=' . $key);

    try {
        $plain = "APP_ENV=test\nSECRET_VALUE=not-for-artifacts\n";
        file_put_contents($root . '/.env', $plain);

        $protector = new EnvironmentFileProtector(Foundation::cli([
            'app' => ['base_path' => $root],
        ]));
        $encrypted = $protector->encrypt(
            input: '.env',
            output: '.env.encrypted',
            keyEnvironment: $environment,
        );
        expect($encrypted)->toBe($root . '/.env.encrypted')
            ->and(file_get_contents($encrypted))->not->toContain('not-for-artifacts');

        unlink($root . '/.env');
        $decrypted = $protector->decrypt(
            input: '.env.encrypted',
            output: '.env',
            keyEnvironment: $environment,
        );
        expect(file_get_contents($decrypted))->toBe($plain);

        $tampered = $root . '/.env.tampered';
        $payload = file_get_contents($encrypted);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Expected protected environment payload.');
        }
        file_put_contents(
            $tampered,
            substr($payload, 0, -1) . (str_ends_with($payload, 'A') ? 'B' : 'A'),
        );
        $preserved = $root . '/preserved.env';
        file_put_contents($preserved, 'preserve-me');

        expect(fn() => $protector->decrypt(
            input: '.env.tampered',
            output: 'preserved.env',
            keyEnvironment: $environment,
            force: true,
        ))->toThrow(DecryptionException::class)
            ->and(file_get_contents($preserved))->toBe('preserve-me');
    } finally {
        unset($_ENV[$environment], $_SERVER[$environment]);
        putenv($environment);
        foundationEnvironmentProtectionRemove($root);
    }
});

function foundationEnvironmentProtectionRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
