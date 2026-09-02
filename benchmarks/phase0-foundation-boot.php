<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

const DEFAULT_REPETITIONS = 15;

/** @return array<string, mixed> */
function config(string $basePath): array
{
    return [
        'app' => ['base_path' => $basePath],
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => ['web.php'],
            'middleware' => [
                'globals' => [
                    'pre' => [],
                    'post' => [],
                ],
            ],
        ],
        'session' => ['driver' => 'array'],
    ];
}

/** @param list<float|int> $values */
function statistics(array $values): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        return [];
    }

    $percentile = static function (float $fraction) use ($values, $count): float|int {
        $index = (int) ceil($fraction * $count) - 1;

        return $values[max(0, min($count - 1, $index))];
    };

    return [
        'minimum' => $values[0],
        'average' => array_sum($values) / $count,
        'p50' => $percentile(0.50),
        'p95' => $percentile(0.95),
        'p99' => $percentile(0.99),
        'maximum' => $values[$count - 1],
    ];
}

function child(string $basePath): never
{
    $started = hrtime(true);
    require getcwd() . '/vendor/autoload.php';
    $autoloaded = hrtime(true);

    Foundation::web(config($basePath))->boot();
    $booted = hrtime(true);

    fwrite(STDOUT, json_encode([
        'autoload_ns' => $autoloaded - $started,
        'application_boot_ns' => $booted - $autoloaded,
        'total_ns' => $booted - $started,
        'memory_bytes' => memory_get_usage(true),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR) . PHP_EOL);

    exit(0);
}

/** @return array<string, mixed> */
function cold(string $basePath, int $repetitions): array
{
    $samples = [];
    $script = __FILE__;

    for ($i = 0; $i < $repetitions; ++$i) {
        $command = [PHP_BINARY, $script, 'child', $basePath];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, getcwd());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start cold boot child process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Cold boot child failed: ' . trim((string) $stderr));
        }

        $sample = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($sample)) {
            throw new RuntimeException('Cold boot child returned an invalid sample.');
        }
        $samples[] = $sample;
    }

    return summarize($samples);
}

/** @return array<string, mixed> */
function warm(string $basePath, int $repetitions): array
{
    require getcwd() . '/vendor/autoload.php';

    // Prime Composer, OPcache and Foundation classes once; measured samples below
    // still create and boot a fresh application/container each time.
    Foundation::web(config($basePath))->boot();

    $samples = [];
    for ($i = 0; $i < $repetitions; ++$i) {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $started = hrtime(true);
        Foundation::web(config($basePath))->boot();
        $booted = hrtime(true);

        $samples[] = [
            'autoload_ns' => 0,
            'application_boot_ns' => $booted - $started,
            'total_ns' => $booted - $started,
            'memory_bytes' => memory_get_usage(true),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }

    return summarize($samples);
}

/**
 * @param list<array<string, mixed>> $samples
 * @return array<string, mixed>
 */
function summarize(array $samples): array
{
    $autoload = [];
    $boot = [];
    $total = [];
    $memory = [];
    $peakMemory = [];

    foreach ($samples as $sample) {
        $autoload[] = (int) ($sample['autoload_ns'] ?? 0);
        $boot[] = (int) ($sample['application_boot_ns'] ?? 0);
        $total[] = (int) ($sample['total_ns'] ?? 0);
        $memory[] = (int) ($sample['memory_bytes'] ?? 0);
        $peakMemory[] = (int) ($sample['peak_memory_bytes'] ?? 0);
    }

    return [
        'samples' => count($samples),
        'autoload_ns' => statistics($autoload),
        'application_boot_ns' => statistics($boot),
        'total_ns' => statistics($total),
        'memory_bytes' => statistics($memory),
        'peak_memory_bytes' => statistics($peakMemory),
    ];
}

$mode = $argv[1] ?? 'suite';
$basePath = $argv[2] ?? getenv('PHASE0_FOUNDATION_BASE') ?: sys_get_temp_dir() . '/foundation-phase0-boot';
$repetitions = max(3, (int) (getenv('PHASE0_BOOT_REPETITIONS') ?: DEFAULT_REPETITIONS));

if ($mode === 'child') {
    child($basePath);
}

$result = [
    'schema' => 1,
    'captured_at_utc' => gmdate(DATE_ATOM),
    'repetitions' => $repetitions,
    'cold_process_boot' => cold($basePath, $repetitions),
    'warm_in_process_boot' => warm($basePath, $repetitions),
];

$output = getenv('PHASE0_BOOT_OUTPUT');
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (is_string($output) && $output !== '') {
    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
    }
    file_put_contents($output, $encoded, LOCK_EX);
}

fwrite(STDOUT, $encoded);
