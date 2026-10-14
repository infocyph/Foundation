<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\FoundationModuleBaselineIO;
use Infocyph\Foundation\Command\CommandDispatcher;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @param list<float> $samples */
function foundationModuleBaselinePercentile(array $samples, float $quantile): float
{
    sort($samples, SORT_NUMERIC);

    return round($samples[max(0, min(count($samples) - 1, (int) ceil(count($samples) * $quantile) - 1))], 3);
}

function foundationModuleBaselineCleanup(string $folder): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($folder);
}

$repetitions = (int) (getenv('FOUNDATION_MODULE_BASELINE_REPETITIONS') ?: 15);
if ($repetitions < 5 || $repetitions > 100) {
    throw new InvalidArgumentException('Module CLI baseline requires 5-100 repetitions.');
}

$root = sys_get_temp_dir() . '/foundation4-module-baseline-' . bin2hex(random_bytes(6));
if (!mkdir($root . '/bin', 0775, true)) {
    throw new RuntimeException('Failed to initialize isolated module benchmark fixture.');
}

$originalPath = getenv('PATH');
try {
    $composerFixture = json_encode([
        'name' => 'foundation/module-baseline',
        'require' => [
            'infocyph/dblayer' => '^6.1',
            'infocyph/omnibus' => '^3.0',
            'infocyph/otp' => '^7.0',
        ],
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    file_put_contents($root . '/composer.json', $composerFixture, LOCK_EX);
    file_put_contents($root . '/bin/composer', "#!/bin/sh\nexit 0\n", LOCK_EX);
    chmod($root . '/bin/composer', 0755);
    putenv('PATH=' . $root . '/bin:' . (is_string($originalPath) ? $originalPath : ''));

    $config = [
        'base_path' => $root,
        '_config_cache' => false,
        'app' => [
            'base_path' => $root,
            'env' => 'testing',
            'capabilities' => [],
        ],
    ];
    $commands = [
        'list' => ['infbyte', 'module:list'],
        'show' => ['infbyte', 'module:show', 'database'],
        'doctor' => ['infbyte', 'module:doctor', 'messaging'],
        'plan' => ['infbyte', 'module:plan', 'auth', '--feature=passkey'],
        'install_dry_run' => ['infbyte', 'module:install', 'database', '--dry-run'],
        'remove_dry_run' => ['infbyte', 'module:remove', 'database', '--dry-run'],
    ];
    $benchmarks = [];
    foreach ($commands as $name => $arguments) {
        $dispatcher = CommandDispatcher::project(
            $config,
            manifestPath: $root . '/bootstrap/cache/commands.php',
            routesPath: $root . '/routes/console.php',
        );
        $io = new FoundationModuleBaselineIO();
        $start = hrtime(true);
        $exit = $dispatcher->run($arguments, $io);
        $cold = (hrtime(true) - $start) / 1_000_000;
        if ($io->errors !== [] || $exit !== 0 || !is_array($io->payload)) {
            throw new RuntimeException(sprintf(
                'Existing module baseline %s failed: exit=%d errors=%s',
                $name,
                $exit,
                implode('; ', $io->errors),
            ));
        }

        if ($name === 'list' && count($io->payload) !== 11) {
            throw new RuntimeException('Existing module inventory unexpectedly differs from 11 entries.');
        }
        if ($name === 'plan' && ($io->payload['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Existing module plan schema changed before the rework.');
        }

        $warm = [];
        for ($n = 0; $n < $repetitions; $n++) {
            $io = new FoundationModuleBaselineIO();
            $start = hrtime(true);
            $exit = $dispatcher->run($arguments, $io);
            $warm[] = (hrtime(true) - $start) / 1_000_000;
            if ($io->errors !== [] || $exit !== 0) {
                throw new RuntimeException(sprintf('Existing module baseline %s failed during warm sampling.', $name));
            }
        }

        $benchmarks[$name] = [
            'cold_ms' => round($cold, 3),
            'warm_p50_ms' => foundationModuleBaselinePercentile($warm, 0.50),
            'warm_p95_ms' => foundationModuleBaselinePercentile($warm, 0.95),
            'warm_p99_ms' => foundationModuleBaselinePercentile($warm, 0.99),
            'warm_samples' => $repetitions,
        ];
    }
    if (file_get_contents($root . '/composer.json') !== $composerFixture) {
        throw new RuntimeException('Module benchmark unexpectedly mutated application Composer requirements.');
    }
    $source = dirname(__DIR__);
    $result = [
        'schema_version' => 1,
        'classification' => 'pre-rework module CLI baseline, synthetic local Composer process, not host RPM certification',
        'source_sha' => getenv('FOUNDATION_MODULE_SOURCE_SHA') ?: (getenv('GITHUB_SHA') ?: 'working-tree'),
        'source_composer_sha256' => hash_file('sha256', $source . '/composer.json'),
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
        'fixture' => 'direct DBLayer + Omnibus + OTP, explicit empty application capabilities, isolated Composer stub',
        'module_count' => 11,
        'commands' => $benchmarks,
        'peak_process_php_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 3),
        'memory_after_samples_mb' => round(memory_get_usage(true) / 1048576, 3),
    ];

    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $output = getenv('FOUNDATION_MODULE_BASELINE_OUTPUT');
    if (is_string($output) && $output !== '' && file_put_contents($output, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist module baseline artifact.');
    }
    fwrite(STDOUT, $json);
} finally {
    is_string($originalPath) ? putenv('PATH=' . $originalPath) : putenv('PATH');
    foundationModuleBaselineCleanup($root);
}
