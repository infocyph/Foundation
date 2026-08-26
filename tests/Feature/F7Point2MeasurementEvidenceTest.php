<?php

declare(strict_types=1);

use function Pest\Faker\fake;

/**
 * @param list<string> $command
 */
function f7Point2Run(array $command,string $cwd): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start F7 point 2 evidence command.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            "F7 point 2 command failed (%d): %s\n%s\n%s",
            $exitCode,
            implode(' ', $command),
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        ));
    }

    return is_string($stdout) ? $stdout : '';
}

function f7Point2RemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            if (!rmdir($entry->getPathname())) {
                throw new RuntimeException(sprintf('Unable to remove F7 evidence directory "%s".', $entry->getPathname()));
            }
            continue;
        }

        if (!unlink($entry->getPathname())) {
            throw new RuntimeException(sprintf('Unable to remove F7 evidence file "%s".', $entry->getPathname()));
        }
    }

    if (!rmdir($directory)) {
        throw new RuntimeException(sprintf('Unable to remove F7 evidence root "%s".', $directory));
    }
}

test('records Foundation 2.0 and candidate benchmark measurements on the F7 evidence runner', function (): void {
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        expect(true)->toBeTrue();

        return;
    }

    $root = dirname(__DIR__, 2);
    $baseline = sys_get_temp_dir() . '/foundation-f7-point-2-' . bin2hex(random_bytes(6));
    $fingerprint = sprintf(
        '%s-%s-php%s-run%s',
        getenv('RUNNER_OS') ?: php_uname('s'),
        getenv('RUNNER_ARCH') ?: php_uname('m'),
        PHP_MAJOR_VERSION . PHP_MINOR_VERSION,
        getenv('GITHUB_RUN_ID') ?: 'manual',
    );

    try {
        f7Point2Run([
            'git',
            'clone',
            '--depth=1',
            '--branch',
            '2.0',
            'https://github.com/infocyph/Foundation.git',
            $baseline,
        ], $root);
        f7Point2Run([
            'composer',
            'update',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
            '--prefer-stable',
        ], $baseline);

        f7Point2Run([
            'env',
            'FOUNDATION_BENCHMARK_STABLE=1',
            'FOUNDATION_BENCHMARK_FINGERPRINT=' . $fingerprint,
            'FOUNDATION_BENCHMARK_REF=2.0',
            'composer',
            'benchmark:representative',
        ], $baseline);
        f7Point2Run([
            'env',
            'FOUNDATION_BENCHMARK_STABLE=1',
            'FOUNDATION_BENCHMARK_FINGERPRINT=' . $fingerprint,
            'FOUNDATION_BENCHMARK_REF=' . (getenv('GITHUB_SHA') ?: 'feature/oauth-2.1'),
            'composer',
            'benchmark:representative',
        ], $root);

        f7Point2Run([
            'env',
            'FOUNDATION_BENCHMARK_FINGERPRINT=' . $fingerprint,
            'FOUNDATION_BENCHMARK_REF=2.0',
            'php',
            $root . '/tools/f7-existing-path-benchmark.php',
            'build/f7-existing-path.json',
        ], $baseline);
        f7Point2Run([
            'env',
            'FOUNDATION_BENCHMARK_FINGERPRINT=' . $fingerprint,
            'FOUNDATION_BENCHMARK_REF=' . (getenv('GITHUB_SHA') ?: 'feature/oauth-2.1'),
            'php',
            $root . '/tools/f7-existing-path-benchmark.php',
            'build/f7-existing-path.json',
        ], $root);

        $baselineRepresentative = json_decode(
            (string) file_get_contents($baseline . '/build/benchmark-result.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $candidateRepresentative = json_decode(
            (string) file_get_contents($root . '/build/benchmark-result.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $baselineExistingPath = json_decode(
            (string) file_get_contents($baseline . '/build/f7-existing-path.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $candidateExistingPath = json_decode(
            (string) file_get_contents($root . '/build/f7-existing-path.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $evidence = [
            'schema_version' => 1,
            'point' => 'F7.2',
            'recorded_at_utc' => gmdate(DATE_ATOM),
            'runner' => [
                'os' => getenv('RUNNER_OS') ?: php_uname('s'),
                'arch' => getenv('RUNNER_ARCH') ?: php_uname('m'),
                'php_version' => PHP_VERSION,
                'run_id' => getenv('GITHUB_RUN_ID') ?: null,
                'fingerprint' => $fingerprint,
            ],
            'baseline' => [
                'ref' => '2.0',
                'representative' => $baselineRepresentative,
                'existing_application_bearer' => $baselineExistingPath,
            ],
            'candidate' => [
                'ref' => getenv('GITHUB_SHA') ?: 'feature/oauth-2.1',
                'representative' => $candidateRepresentative,
                'existing_application_bearer' => $candidateExistingPath,
            ],
        ];

        $evidenceDirectory = $root . '/docs/evidence';
        if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0775, true) && !is_dir($evidenceDirectory)) {
            throw new RuntimeException('Unable to create F7 evidence directory.');
        }

        $evidencePath = $evidenceDirectory . '/f7-point-2-measurements.json';
        if (file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
            throw new RuntimeException('Unable to write F7 point 2 evidence.');
        }

        f7Point2Run(['git', 'add', 'docs/evidence/f7-point-2-measurements.json'], $root);

        expect($baselineExistingPath['median_rpm'] ?? 0)->toBeGreaterThan(0)
            ->and($candidateExistingPath['median_rpm'] ?? 0)->toBeGreaterThan(0)
            ->and($baselineRepresentative)->toBeArray()
            ->and($candidateRepresentative)->toBeArray();
    } finally {
        f7Point2RemoveDirectory($baseline);
    }
});
