<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;

final class FoundationEnvironmentStorageIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $json = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Choice input is not expected in this test IO.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void {}

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->json[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void {}

    public function password(string $question): string
    {
        throw new LogicException('Password input is not expected in this test IO.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Text input is not expected in this test IO.');
    }

    public function success(string $message): void {}

    public function table(array $headers, array $rows): void {}

    public function warning(string $message): void {}

    public function write(string $message): void {}

    public function writeln(string $message = ''): void {}
}

it('protects environment files atomically with restrictive permissions and safe overwrite behavior', function (): void {
    $basePath = foundationEnvironmentStorageProject('environment');
    $dispatcher = foundationEnvironmentStorageDispatcher($basePath);
    $source = $basePath . '/.env.source';
    $encrypted = $basePath . '/.env.encrypted';
    $restored = $basePath . '/.env.restored';
    $keyFile = $basePath . '/.env.key';
    $wrongKeyFile = $basePath . '/.env.wrong-key';
    $plaintext = "APP_ENV=production\nAPP_NAME=Foundation\n";

    file_put_contents($source, $plaintext);
    file_put_contents($keyFile, foundationEnvironmentStorageKey());
    file_put_contents($wrongKeyFile, foundationEnvironmentStorageKey());

    try {
        $encryptedIO = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:encrypt', '--input=.env.source', '--output=.env.encrypted', '--key-file=.env.key',
        ]);
        expect($encryptedIO['exit'])->toBe(ExitCode::SUCCESS)
            ->and($encryptedIO['io']->json[0]['operation'] ?? null)->toBe('encrypt')
            ->and(is_file($encrypted))->toBeTrue()
            ->and(file_get_contents($encrypted))->not->toBe($plaintext)
            ->and(fileperms($encrypted) & 0777)->toBe(0600);
        foundationEnvironmentStorageExpectNoStages($basePath);

        $decryptedIO = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:decrypt', '--input=.env.encrypted', '--output=.env.restored', '--key-file=.env.key',
        ]);
        expect($decryptedIO['exit'])->toBe(ExitCode::SUCCESS)
            ->and($decryptedIO['io']->json[0]['operation'] ?? null)->toBe('decrypt')
            ->and(file_get_contents($restored))->toBe($plaintext)
            ->and(fileperms($restored) & 0777)->toBe(0600);
        foundationEnvironmentStorageExpectNoStages($basePath);

        file_put_contents($restored, 'preserve-without-force');
        $refused = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:decrypt', '--input=.env.encrypted', '--output=.env.restored', '--key-file=.env.key',
        ]);
        expect($refused['exit'])->toBe(ExitCode::FAILURE)
            ->and(file_get_contents($restored))->toBe('preserve-without-force')
            ->and(implode(' ', $refused['io']->errors))->toContain('use --force to replace it');
        foundationEnvironmentStorageExpectNoStages($basePath);

        $forced = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:decrypt', '--input=.env.encrypted', '--output=.env.restored', '--key-file=.env.key', '--force',
        ]);
        expect($forced['exit'])->toBe(ExitCode::SUCCESS)
            ->and(file_get_contents($restored))->toBe($plaintext)
            ->and(fileperms($restored) & 0777)->toBe(0600);
        foundationEnvironmentStorageExpectNoStages($basePath);

        file_put_contents($restored, 'must-survive-failed-decryption');
        $failed = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:decrypt', '--input=.env.encrypted', '--output=.env.restored', '--key-file=.env.wrong-key', '--force',
        ]);
        expect($failed['exit'])->toBe(ExitCode::FAILURE)
            ->and(file_get_contents($restored))->toBe('must-survive-failed-decryption');
        foundationEnvironmentStorageExpectNoStages($basePath);

        $outside = $basePath . '/outside.env';
        file_put_contents($outside, 'outside-must-remain');
        $symlink = $basePath . '/.env.symlink';
        if (!symlink($outside, $symlink)) {
            throw new RuntimeException('Unable to create environment safety symlink fixture.');
        }
        $symlinkRefused = foundationEnvironmentStorageRun($dispatcher, [
            'infbyte', 'env:decrypt', '--input=.env.encrypted', '--output=.env.symlink', '--key-file=.env.key', '--force',
        ]);
        expect($symlinkRefused['exit'])->toBe(ExitCode::FAILURE)
            ->and(is_link($symlink))->toBeTrue()
            ->and(file_get_contents($outside))->toBe('outside-must-remain')
            ->and(implode(' ', $symlinkRefused['io']->errors))->toContain('refuses to replace symbolic-link targets');
        foundationEnvironmentStorageExpectNoStages($basePath);
    } finally {
        foundationEnvironmentStorageRemove($basePath);
    }
});

it('creates reports and removes only configured matching storage links', function (): void {
    $basePath = foundationEnvironmentStorageProject('storage');
    $dispatcher = foundationEnvironmentStorageDispatcher($basePath, [
        'public/storage' => 'storage/app/public',
    ]);
    $link = $basePath . '/public/storage';
    $target = $basePath . '/storage/app/public';

    try {
        $created = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:link']);
        expect($created['exit'])->toBe(ExitCode::SUCCESS)
            ->and($created['io']->json[0][0]['created'] ?? null)->toBeTrue()
            ->and(is_link($link))->toBeTrue()
            ->and(realpath($link))->toBe(realpath($target));
        expect(glob($link . '.*.tmp') ?: [])->toBe([]);

        $createdAgain = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:link']);
        expect($createdAgain['exit'])->toBe(ExitCode::SUCCESS)
            ->and($createdAgain['io']->json[0][0]['created'] ?? null)->toBeFalse();

        $status = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:status']);
        expect($status['exit'])->toBe(ExitCode::SUCCESS)
            ->and($status['io']->json[0][0]['exists'] ?? null)->toBeTrue()
            ->and($status['io']->json[0][0]['linked'] ?? null)->toBeTrue()
            ->and($status['io']->json[0][0]['matches'] ?? null)->toBeTrue();

        $removed = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:unlink']);
        expect($removed['exit'])->toBe(ExitCode::SUCCESS)
            ->and($removed['io']->json[0][0]['removed'] ?? null)->toBeTrue()
            ->and(is_link($link))->toBeFalse()
            ->and(is_dir($target))->toBeTrue();

        $removedAgain = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:unlink']);
        expect($removedAgain['exit'])->toBe(ExitCode::SUCCESS)
            ->and($removedAgain['io']->json[0][0]['removed'] ?? null)->toBeFalse();

        mkdir($link, 0775, true);
        file_put_contents($link . '/keep.txt', 'keep');
        $nonLinkRefused = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:unlink']);
        expect($nonLinkRefused['exit'])->toBe(ExitCode::FAILURE)
            ->and(is_dir($link))->toBeTrue()
            ->and(file_get_contents($link . '/keep.txt'))->toBe('keep')
            ->and(implode(' ', $nonLinkRefused['io']->errors))->toContain('Refusing to remove non-symbolic path');
        foundationEnvironmentStorageRemove($link);

        $otherTarget = $basePath . '/storage/app/other';
        mkdir($otherTarget, 0775, true);
        if (!symlink($otherTarget, $link)) {
            throw new RuntimeException('Unable to create mismatched storage-link fixture.');
        }
        $mismatchRefused = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:unlink']);
        expect($mismatchRefused['exit'])->toBe(ExitCode::FAILURE)
            ->and(is_link($link))->toBeTrue()
            ->and(is_dir($otherTarget))->toBeTrue()
            ->and(implode(' ', $mismatchRefused['io']->errors))->toContain('current target does not match configuration');
    } finally {
        foundationEnvironmentStorageRemove($basePath);
    }
});

it('rejects storage link traversal outside Foundation public and storage roots', function (): void {
    $basePath = foundationEnvironmentStorageProject('traversal');
    $dispatcher = foundationEnvironmentStorageDispatcher($basePath, [
        'public/bad' => 'storage/../outside',
    ]);

    try {
        $result = foundationEnvironmentStorageRun($dispatcher, ['infbyte', 'storage:link']);
        expect($result['exit'])->toBe(ExitCode::FAILURE)
            ->and(implode(' ', $result['io']->errors))->toContain('cannot contain parent-directory traversal')
            ->and(file_exists($basePath . '/outside'))->toBeFalse();
    } finally {
        foundationEnvironmentStorageRemove($basePath);
    }
});

/** @param array<string, string> $links */
function foundationEnvironmentStorageDispatcher(string $basePath, array $links = []): CommandDispatcher
{
    return CommandDispatcher::project(
        [
            'base_path' => $basePath,
            'app' => ['base_path' => $basePath, 'env' => 'testing'],
            'filesystem' => ['links' => $links],
        ],
        manifestPath: $basePath . '/bootstrap/cache/commands.php',
        routesPath: $basePath . '/routes/console.php',
    );
}

function foundationEnvironmentStorageExpectNoStages(string $basePath): void
{
    expect(glob($basePath . '/.*.tmp') ?: [])->toBe([])
        ->and(glob($basePath . '/.*.bak') ?: [])->toBe([]);
}

function foundationEnvironmentStorageKey(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function foundationEnvironmentStorageProject(string $suffix): string
{
    $basePath = sys_get_temp_dir() . '/foundation-safety-' . $suffix . '-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/routes', 0775, true);
    mkdir($basePath . '/public', 0775, true);
    mkdir($basePath . '/storage', 0775, true);

    return $basePath;
}

/**
 * @param list<string> $argv
 * @return array{exit:int,io:FoundationEnvironmentStorageIO}
 */
function foundationEnvironmentStorageRun(CommandDispatcher $dispatcher, array $argv): array
{
    $io = new FoundationEnvironmentStorageIO();

    return ['exit' => $dispatcher->run($argv, $io), 'io' => $io];
}

function foundationEnvironmentStorageRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        foundationEnvironmentStorageRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($path);
}
