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

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in cache schema command tests.');
    }

    #[\Override]
    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    #[\Override]
    public function info(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function interactive(): bool
    {
        return false;
    }

    #[\Override]
    public function json(mixed $value): void
    {
        $this->payloads[] = $value;
    }

    #[\Override]
    public function machineReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function note(string $message): void
    {
        unset($message);
    }

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in cache schema command tests.');
    }

    #[\Override]
    public function quiet(): bool
    {
        return false;
    }

    /**
     * @throws \LogicException
     */
    #[\Override]
    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in cache schema command tests.');
    }

    #[\Override]
    public function success(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    #[\Override]
    public function warning(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function write(string $message): void
    {
        unset($message);
    }

    #[\Override]
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

/** @throws \UnexpectedValueException */
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
        if (!($file instanceof \SplFileInfo)) {
            throw new \UnexpectedValueException('Unexpected directory iterator entry.');
        }

        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
