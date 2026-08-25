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

it('keeps optimize and optimize clear idempotent across every runtime artifact', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-optimize-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents($basePath . '/routes/web.php', <<<'PHP'
<?php
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/health', static fn(): array => ['ok' => true], ['name' => 'health']);
PHP);

    $dispatcher = CommandDispatcher::project(
        [
            'base_path' => $basePath,
            'app' => [
                'base_path' => $basePath,
                'env' => 'testing',
                'container' => [
                    'compiled_activation' => 'off',
                ],
            ],
        ],
        manifestPath: $basePath . '/bootstrap/cache/commands.php',
        routesPath: $basePath . '/routes/console.php',
    );

    try {
        $firstPayload = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize'));
        $secondPayload = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize'));
        $secondContainers = $secondPayload['containers'] ?? null;
        if (!is_array($secondContainers)) {
            throw new RuntimeException('Second optimization did not expose compiled runtime containers.');
        }
        $secondRuntimeKeys = array_keys($secondContainers);
        sort($secondRuntimeKeys);

        expect($firstPayload['artifacts'] ?? null)->toBe($secondPayload['artifacts'] ?? null)
            ->and($secondRuntimeKeys)->toBe(['cli', 'scheduler', 'web', 'worker']);

        $warm = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:report'));
        expect($warm['config'] ?? null)->toBeTrue()
            ->and($warm['routes'] ?? null)->toBeTrue()
            ->and($warm['commands'] ?? null)->toBeTrue()
            ->and($warm['schedule'] ?? null)->toBeTrue()
            ->and($warm['optimize_manifest'] ?? null)->toBeTrue();
        foundationOptimizationExpectContainers($warm, true);

        $firstClear = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:clear'));
        expect($firstClear)->toBe([
            'config' => true,
            'routes' => true,
            'commands' => true,
            'schedule' => true,
            'containers' => true,
        ]);

        $secondClear = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:clear'));
        expect($secondClear)->toBe([
            'config' => false,
            'routes' => false,
            'commands' => false,
            'schedule' => false,
            'containers' => false,
        ]);

        $cold = foundationOptimizationPayload(foundationOptimizationRun($dispatcher, 'optimize:report'));
        expect($cold['config'] ?? null)->toBeFalse()
            ->and($cold['routes'] ?? null)->toBeFalse()
            ->and($cold['commands'] ?? null)->toBeFalse()
            ->and($cold['schedule'] ?? null)->toBeFalse()
            ->and($cold['optimize_manifest'] ?? null)->toBeFalse();
        foundationOptimizationExpectContainers($cold, false);
    } finally {
        foundationOptimizationRemove($basePath);
    }
});

/** @param array<string, mixed> $payload */
function foundationOptimizationExpectContainers(array $payload, bool $ready): void
{
    $containers = $payload['containers'] ?? null;
    if (!is_array($containers)) {
        throw new RuntimeException('Optimization report did not expose container status.');
    }
    $runtimeKeys = array_keys($containers);
    sort($runtimeKeys);

    expect($runtimeKeys)->toBe(['cli', 'scheduler', 'web', 'worker']);
    foreach ($containers as $runtime => $status) {
        if (!is_array($status)) {
            throw new RuntimeException(sprintf('Optimization status for %s is invalid.', (string) $runtime));
        }

        expect($status['runtime'] ?? null)->toBe($runtime)
            ->and($status['ready'] ?? null)->toBe($ready);

        $compiled = $status['compiled'] ?? null;
        if ($ready) {
            expect($compiled)->toBeInt()->toBeGreaterThan(0);
        } else {
            expect($compiled)->toBe(0);
        }
    }
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
