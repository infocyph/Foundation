<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;

final class FoundationOptimizationCommandIO implements CommandIO
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

it('keeps optimize and optimize clear idempotent across release generations', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-optimize-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents($basePath . '/routes/web.php', <<<'PHP'
<?php
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/health', static fn(): array => ['ok' => true], ['name' => 'health']);
PHP);

    $dispatcher = foundationOptimizationDispatcher($basePath, []);

    try {
        $first = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize'));
        $second = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize'));

        expect($first['generation'] ?? null)->toBeString()->not->toBe('')
            ->and($second['generation'] ?? null)->toBeString()->not->toBe('')
            ->and($second['generation'] ?? null)->not->toBe($first['generation'] ?? null)
            ->and($second['manifest_sha256'] ?? null)->toBeString()->toHaveLength(64)
            ->and(is_file((string) ($second['manifest'] ?? '')))->toBeTrue()
            ->and(is_file((string) ($second['active_pointer'] ?? '')))->toBeTrue();

        $warm = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:report'));
        expect($warm['ready'] ?? null)->toBeTrue()
            ->and($warm['generation'] ?? null)->toBe($second['generation'] ?? null)
            ->and($warm['manifest'] ?? null)->toBe($second['manifest'] ?? null)
            ->and($warm['manifest_sha256'] ?? null)->toBe($second['manifest_sha256'] ?? null);

        $firstClear = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:clear'));
        expect($firstClear['removed'] ?? null)->toBeTrue();

        $secondClear = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:clear'));
        expect($secondClear['removed'] ?? null)->toBeFalse();

        $cold = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:report'));
        expect($cold['ready'] ?? null)->toBeFalse()
            ->and($cold['generation'] ?? null)->toBeNull()
            ->and($cold['manifest'] ?? null)->toBeNull()
            ->and($cold['manifest_sha256'] ?? null)->toBeNull();
    } finally {
        foundationOptimizationRemove($basePath);
    }
});

it('requires explicit production capability topology for optimize', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-optimize-capabilities-' . bin2hex(random_bytes(6));
    $dispatcher = foundationOptimizationDispatcher($basePath, null);

    try {
        $io = new FoundationOptimizationCommandIO();
        $exit = $dispatcher->run(['infbyte', 'optimize'], $io);

        expect($exit)->toBe(ExitCode::FAILURE)
            ->and($io->errors)->toContain('Production optimization requires an explicit app.capabilities list or map.');
    } finally {
        foundationOptimizationRemove($basePath);
    }
});

/** @param list<string>|null $capabilities */
function foundationOptimizationDispatcher(string $basePath, ?array $capabilities): CommandDispatcher
{
    $app = [
        'base_path' => $basePath,
        'env' => 'testing',
        'container' => [
            'compiled_activation' => 'off',
        ],
    ];
    if ($capabilities !== null) {
        $app['capabilities'] = $capabilities;
    }

    return CommandDispatcher::project(
        [
            'base_path' => $basePath,
            'app' => $app,
        ],
        manifestPath: $basePath . '/bootstrap/cache/commands.php',
        routesPath: $basePath . '/routes/console.php',
    );
}

/** @return array<string, mixed> */
function foundationOptimizationPayload(FoundationOptimizationCommandIO $io): array
{
    $payload = $io->json[0] ?? null;
    if (!is_array($payload)) {
        throw new RuntimeException('Optimization command did not emit a machine-readable payload.');
    }

    return $payload;
}

function foundationOptimizationRun(CommandDispatcher $dispatcher, string $command): FoundationOptimizationCommandIO
{
    $io = new FoundationOptimizationCommandIO();
    $exit = $dispatcher->run(['infbyte', $command], $io);
    if ($exit !== ExitCode::SUCCESS) {
        throw new RuntimeException(sprintf(
            '%s failed with exit %d: %s',
            $command,
            $exit,
            implode('; ', $io->errors),
        ));
    }

    return $io;
}

function foundationOptimizationRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            foundationOptimizationRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
