<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;

final class FoundationCacheSchemaCommandIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $payloads = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in cache schema command tests.');
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

    public function info(string $message): void
    {
        unset($message);
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->payloads[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in cache schema command tests.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in cache schema command tests.');
    }

    public function success(string $message): void
    {
        unset($message);
    }

    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    public function warning(string $message): void
    {
        unset($message);
    }

    public function write(string $message): void
    {
        unset($message);
    }

    public function writeln(string $message = ''): void
    {
        unset($message);
    }

    public function lastPayload(): mixed
    {
        return $this->payloads === [] ? null : $this->payloads[array_key_last($this->payloads)];
    }
}

it('manages database-backed CacheLayer schemas through core cache commands', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-schema-command-' . bin2hex(random_bytes(5));
    $cachePath = $basePath . '/storage/cache/cachelayer.sqlite';
    mkdir($basePath . '/storage/cache', 0775, true);

    $dispatcher = CommandDispatcher::project([
        'base_path' => $basePath,
        '_config_cache' => false,
        'app' => [
            'base_path' => $basePath,
            'env' => 'testing',
            'capabilities' => ['cache'],
        ],
        'cache' => [
            'default' => 'sqlite',
            'stores' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'path' => 'storage/cache/cachelayer.sqlite',
                    'table' => 'foundation_cache_entries',
                ],
            ],
            'transports' => [],
            'clusters' => [],
        ],
    ], manifestPath: $basePath . '/bootstrap/cache/commands.php', routesPath: $basePath . '/routes/console.php');

    try {
        $before = new FoundationCacheSchemaCommandIO();
        expect($dispatcher->run(['infbyte', 'cache:schema:status'], $before))
            ->toBe(ExitCode::FAILURE);

        $beforePayload = $before->lastPayload();
        expect($beforePayload)->toBeArray()
            ->and($beforePayload['schemas'][0]['name'] ?? null)->toBe('cache:store:sqlite')
            ->and($beforePayload['schemas'][0]['state'] ?? null)->toBe('pending')
            ->and($beforePayload['schemas'][0]['installed'] ?? null)->toBeFalse();

        $install = new FoundationCacheSchemaCommandIO();
        expect($dispatcher->run(['infbyte', 'cache:schema:install'], $install))
            ->toBe(ExitCode::SUCCESS);

        $installPayload = $install->lastPayload();
        expect($cachePath)->toBeFile()
            ->and($installPayload)->toBeArray()
            ->and($installPayload['schemas'][0]['state'] ?? null)->toBe('installed')
            ->and($installPayload['schemas'][0]['installed'] ?? null)->toBeTrue();

        $after = new FoundationCacheSchemaCommandIO();
        expect($dispatcher->run(['infbyte', 'cache:schema:status'], $after))
            ->toBe(ExitCode::SUCCESS)
            ->and($after->lastPayload()['schemas'][0]['state'] ?? null)->toBe('installed');
    } finally {
        foundationCacheSchemaCommandRemoveDirectory($basePath);
    }
});

function foundationCacheSchemaCommandRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
