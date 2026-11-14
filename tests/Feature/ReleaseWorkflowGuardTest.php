<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('bounds transient repository setup retries and preserves fatal setup failures', function (string $mode, int $expectedExit, int $expectedAttempts): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/foundation-host-setup-' . bin2hex(random_bytes(8));
    if (!mkdir($directory . '/bin', 0700, true)) {
        throw new \RuntimeException('Unable to allocate the isolated host-setup test directory.');
    }
    $sudo = <<<'SH'
    #!/usr/bin/env bash
    set -euo pipefail
    printf '%s\n' "$*" >> "$FOUNDATION_SETUP_FIXTURE_DIRECTORY/calls"
    if [[ "$1" != add-apt-repository ]]; then exit 0; fi
    count=0
    if [[ -f "$FOUNDATION_SETUP_FIXTURE_DIRECTORY/attempts" ]]; then
        read -r count < "$FOUNDATION_SETUP_FIXTURE_DIRECTORY/attempts"
    fi
    count=$((count + 1))
    printf '%s\n' "$count" > "$FOUNDATION_SETUP_FIXTURE_DIRECTORY/attempts"
    case "$FOUNDATION_SETUP_FIXTURE_MODE" in
      fatal) printf 'Invalid PPA specification.\n' >&2; exit 2 ;;
      timeout) if (( count < 3 )); then exit 124; fi ;;
      flaky|exhausted)
        if (( count < 3 )) || [[ "$FOUNDATION_SETUP_FIXTURE_MODE" == exhausted ]]; then
          printf "%s\n" "ValueError: invalid literal for int() with base 16: b''" >&2
          exit 1
        fi
        ;;
      *) exit 99 ;;
    esac
    SH;
    try {
        foreach (['sudo' => $sudo, 'sleep' => "#!/bin/sh\nexit 0\n"] as $name => $contents) {
            $executable = $directory . '/bin/' . $name;
            if (file_put_contents($executable, $contents) !== strlen($contents)
                || !chmod($executable, 0700)
                || !is_executable($executable)) {
                throw new \RuntimeException('Unable to install the isolated host-setup command fixtures.');
            }
        }
        $path = getenv('PATH');
        $process = new \Symfony\Component\Process\Process([
            'bash', $root . '/benchmarks/foundation4-host-prerequisites.sh',
        ], $root, [
            'PATH' => $directory . '/bin:' . (is_string($path) ? $path : ''),
            'FOUNDATION_SETUP_FIXTURE_DIRECTORY' => $directory,
            'FOUNDATION_SETUP_FIXTURE_MODE' => $mode,
        ]);
        $process->run();
        expect($process->getExitCode())->toBe($expectedExit, $process->getOutput() . $process->getErrorOutput())
            ->and((int) file_get_contents($directory . '/attempts'))->toBe($expectedAttempts);
        $calls = (string) file_get_contents($directory . '/calls');
        expect(str_contains($calls, 'nginx php8.4-fpm'))->toBe($expectedExit === 0);
    } finally {
        \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::removeDirectory($directory);
    }
})->with([
    'truncated transport recovers' => ['flaky', 0, 3],
    'transport retry budget is exhausted' => ['exhausted', 1, 3],
    'fatal repository failure is not retried' => ['fatal', 2, 1],
    'repository timeout recovers' => ['timeout', 0, 3],
]);

it('requires tagged Foundation releases to pass quality and production consumers', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $jobs = $workflow['jobs'];

    expect($jobs['release']['needs'] ?? [])->toContain('phpforge', 'production-consumer', 'release-evidence', 'runtime-hosts', 'redis-cluster', 'documentation')
        ->and($jobs['release']['if'])->toContain("startsWith(github.ref, 'refs/tags/')")
        ->not->toContain('always(', 'failure(', 'cancelled(');

    foreach (['phpforge', 'production-consumer'] as $job) {
        expect($jobs[$job]['if'] ?? null)->toBeNull();
    }

    expect($jobs['phpforge']['uses'])->toBe('infocyph/phpforge/.github/workflows/security-standards.yml@a6232b6aefcc32b84b2fb94b8db8f5ac3b276d5d')
        ->and($jobs['runtime-hosts']['uses'])->toBe('./.github/workflows/foundation4-host-certification.yml')
        ->and($jobs['runtime-hosts']['if'])->toContain("startsWith(github.ref, 'refs/tags/')");
    $hosts = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    expect($hosts['on'])->toHaveKey('workflow_call');
    expect($hosts['on'])->not->toHaveKey('pull_request');
    $nativeSteps = foundationReleaseWorkflowSteps($hosts, 'native-host');
    $pairedSteps = foundationReleaseWorkflowSteps($hosts, 'paired-host');
    expect($nativeSteps['Install native host and load tools'])->toContain('bash benchmarks/foundation4-host-prerequisites.sh')
        ->not->toContain('add-apt-repository');
    expect($pairedSteps['Install host prerequisites'])->toContain('bash host-tools/benchmarks/foundation4-host-prerequisites.sh')
        ->not->toContain('add-apt-repository');
    expect($jobs['runtime-hosts']['if'])->toContain("github.event_name == 'pull_request'", 'foundation4-certify-source');
    expect($jobs['source-certification']['needs'] ?? [])->toContain('phpforge', 'production-consumer', 'release-evidence');
    expect($jobs['source-certification']['if'])->not->toContain('always(', 'failure(');
});

it('enforces independent complete performance batches without mixing their artifacts', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    $measure = $workflow['jobs']['paired-host'] ?? null;
    $compare = $workflow['jobs']['compare-paired-host'] ?? null;
    if (!is_array($measure) || !is_array($compare)
        || !is_array($measure['strategy'] ?? null)
        || !is_array($measure['strategy']['matrix'] ?? null)
        || !is_array($measure['env'] ?? null)
        || !is_array($compare['strategy'] ?? null)
        || !is_array($compare['strategy']['matrix'] ?? null)
        || !is_array($compare['steps'] ?? null)) {
        throw new \UnexpectedValueException('Host workflow must define complete paired measurement and comparison jobs.');
    }

    expect($measure['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($compare['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($measure['strategy']['matrix']['host'])->toBe(['fpm', 'runwire'])
        ->and($measure['strategy']['matrix']['workers'])->toBe([1, 4])
        ->and($measure['strategy']['matrix']['concurrency'])->toBe([1, 10, 50])
        ->and(foundationFixtureInteger($measure['env']['FOUNDATION_HOST_MIN_SECONDS']))->toBeGreaterThanOrEqual(300)
        ->and(foundationFixtureInteger($measure['env']['FOUNDATION_HOST_WARMUP_SECONDS']))->toBeGreaterThanOrEqual(300);

    $download = [];
    $runScripts = [];
    foreach ($compare['steps'] as $step) {
        if (!is_array($step)) {
            throw new \UnexpectedValueException('Comparison steps must be YAML mappings.');
        }
        if (is_array($step['with'] ?? null) && isset($step['with']['pattern'])) {
            $download[] = $step['with']['pattern'];
        }
        if (isset($step['run'])) {
            if (!is_string($step['run'])) {
                throw new \UnexpectedValueException('Comparison shell steps must contain string commands.');
            }
            $runScripts[] = $step['run'];
        }
    }
    expect($download)->toBe(['foundation4-pair-${{ matrix.batch }}-*']);

    $run = implode("\n", $runScripts);
    expect($run)->toContain('foundation4-host-merge.php', 'foundation4-module-cli-compare.php')
        ->not->toContain('|| true', 'continue-on-error');
});

it('captures benchmark configuration using only options supported by its PHP version', function (bool $php85): void {
    $root = dirname(__DIR__, 2);
    $workflow = foundationReleaseWorkflow($root . '/.github/workflows/foundation4-host-certification.yml');
    $steps = foundationReleaseWorkflowSteps($workflow, 'paired-host');
    $directory = sys_get_temp_dir() . '/foundation-ini-capture-' . bin2hex(random_bytes(8));
    mkdir($directory . '/bin', 0700, true);
    mkdir($directory . '/locked-dependencies', 0700);
    file_put_contents($directory . '/locked-dependencies/baseline.lock', '{"packages":[]}');
    file_put_contents($directory . '/locked-dependencies/candidate.lock', '{"packages":[]}');
    $wrapper = "#!/bin/sh\n"
        . 'if [ "$1" = "-r" ]; then case "$2" in *PHP_VERSION_ID*) exit ' . ($php85 ? '0' : '1') . ';; esac; fi' . "\n"
        . 'if [ "$1" = "--ini=diff" ]; then ' . ($php85 ? 'echo enabled; exit 0;' : 'echo unsupported >&2; exit 64;') . ' fi' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"' . "\n";
    file_put_contents($directory . '/bin/php', $wrapper);
    chmod($directory . '/bin/php', 0700);
    $process = new \Symfony\Component\Process\Process([
        'bash', '-e', '-o', 'pipefail', '-c', $steps['Capture exact runtime and dependency identity'],
    ], $directory, ['PATH' => $directory . '/bin:' . (($__foundation157_path = getenv('PATH')) === false ? '' : $__foundation157_path)]);

    try {
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_exists($directory . '/evidence/php-ini-files.txt'))->toBeTrue()
            ->and(file_exists($directory . '/evidence/php-ini-diff.txt'))->toBe($php85);
        $settings = json_decode((string) file_get_contents($directory . '/evidence/php-runtime-settings.json'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException('Expected a decoded JSON object.');
        }
        expect($settings)->toHaveKeys(['opcache.enable_cli', 'opcache.validate_timestamps', 'memory_limit']);
        $digests = (string) file_get_contents($directory . '/evidence/composer-lock.sha256');
        expect($digests)->toContain('locked-dependencies/baseline.lock', 'locked-dependencies/candidate.lock');

    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                throw new \UnexpectedValueException('Workflow fixture cleanup requires filesystem entries.');
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with([false, true]);

it('keeps paired controllers on the load CPU and shares their authentication inputs', function (bool $provided): void {
    $root = dirname(__DIR__, 2);
    $workflow = foundationReleaseWorkflow($root . '/.github/workflows/foundation4-host-certification.yml');
    $steps = foundationReleaseWorkflowSteps($workflow, 'paired-host');
    expect(preg_match('/^Cpus_allowed_list:\s*(\d+)/m', (string) file_get_contents('/proc/self/status'), $matches))->toBe(1);
    $cpu = $matches[1] ?? null;
    if (!is_string($cpu)) {
        throw new \UnexpectedValueException('Controller CPU affinity must be reported.');
    }
    $directory = sys_get_temp_dir() . '/foundation-controller-affinity-' . bin2hex(random_bytes(8));
    $probe = <<<'SH'
    set -euo pipefail
    mkdir -p "$FOUNDATION_HOST_RESULT_DIR"
    awk '/^Cpus_allowed_list:/ { print $2 }' "/proc/$$/status" > "$FOUNDATION_HOST_RESULT_DIR/controller.txt"
    php -r 'preg_match("/^Cpus_allowed_list:\\s*(.+)$/m", file_get_contents("/proc/self/status"), $m); echo trim($m[1]), PHP_EOL;' > "$FOUNDATION_HOST_RESULT_DIR/child.txt"
    php -r '$inputs=[];foreach(["FOUNDATION_HOST_AUTH_SECRET","FOUNDATION_HOST_MFA_SECRET","AUTH_OTP_RECOVERY_HMAC_KEY"] as $key){$value=getenv($key);if(!is_string($value)||$value==="")throw new RuntimeException("Paired authentication inputs must be initialized before either source runs.");$inputs[$key]=hash("sha256",$value);}file_put_contents($argv[1],json_encode($inputs,JSON_THROW_ON_ERROR));' "$FOUNDATION_HOST_RESULT_DIR/inputs.json"
    SH;

    try {
        foreach (['baseline', 'candidate'] as $variant) {
            mkdir($directory . '/' . $variant . '/benchmarks', 0700, true);
            file_put_contents($directory . '/' . $variant . '/benchmarks/foundation4-host-certify.sh', $probe);
        }
        $inputs = [
            'FOUNDATION_HOST_AUTH_SECRET' => $provided ? str_repeat('a', 64) : false,
            'FOUNDATION_HOST_MFA_SECRET' => $provided ? rtrim(base64_encode(str_repeat('b', 32)), '=') : false,
            'AUTH_OTP_RECOVERY_HMAC_KEY' => $provided ? str_repeat('c', 64) : false,
        ];
        $process = new \Symfony\Component\Process\Process([
            'bash', '-e', '-o', 'pipefail', '-c', $steps['Run baseline and candidate on this same physical runner'],
        ], $directory, [
            'GITHUB_WORKSPACE' => $directory,
            'BASELINE_SHA' => str_repeat('1', 40),
            'CANDIDATE_SHA' => str_repeat('2', 40),
            'MEASUREMENT_BATCH' => 'primary',
            'FOUNDATION_HOST_LOAD_CPU_SET' => $cpu,
            'FOUNDATION_HOST_FILTER_HOST' => 'runwire',
            'FOUNDATION_HOST_FILTER_WORKERS' => '4',
            'FOUNDATION_HOST_FILTER_CONCURRENCY' => '10',
            ...$inputs,
        ]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        foreach (['baseline', 'candidate'] as $variant) {
            foreach (['controller', 'child'] as $role) {
                expect(trim((string) file_get_contents($directory . '/evidence/' . $variant . '/' . $role . '.txt')))->toBe($cpu);
            }
        }
        $baselineInputs = (string) file_get_contents($directory . '/evidence/baseline/inputs.json');
        $candidateInputs = (string) file_get_contents($directory . '/evidence/candidate/inputs.json');
        expect($baselineInputs)->toBe($candidateInputs);
        if ($provided) {
            $expected = array_map(static fn(string|false $value): string => hash('sha256', (string) $value), $inputs);
            expect(json_decode($baselineInputs, true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
        }
    } finally {
        (new \Symfony\Component\Process\Process(['rm', '-rf', '--', $directory]))->mustRun();
    }
})->with(['fresh shared inputs' => [false], 'caller supplied inputs' => [true]]);

/**
 * Validate the actual workflow structure before inspecting typed job contracts.
 *
 * @return array{on:array<string,mixed>,jobs:array<string,array<string,mixed>>}
 * @throws \UnexpectedValueException
 * @throws \Symfony\Component\Yaml\Exception\ParseException
 */
function foundationReleaseWorkflow(string $path): array
{
    $document = Yaml::parseFile($path);
    if (!is_array($document) || !is_array($document['on'] ?? null) || !is_array($document['jobs'] ?? null)) {
        throw new \UnexpectedValueException('Release workflow must define event triggers and a jobs map.');
    }

    $jobs = [];
    foreach ($document['jobs'] as $name => $job) {
        if (!is_string($name) || !is_array($job)) {
            throw new \UnexpectedValueException('Release workflow jobs must be named mappings.');
        }
        $jobs[$name] = foundationStringMap($job);
    }

    return ['on' => foundationStringMap($document['on']), 'jobs' => $jobs];
}

/**
 * @param array{on:array<string,mixed>,jobs:array<string,array<string,mixed>>} $workflow
 * @return array<string,string>
 * @throws \UnexpectedValueException
 */
function foundationReleaseWorkflowSteps(array $workflow, string $jobName): array
{
    $steps = $workflow['jobs'][$jobName]['steps'] ?? null;
    if (!is_array($steps)) {
        throw new \UnexpectedValueException('Paired host workflow must define ordered steps.');
    }
    $commands = [];
    foreach ($steps as $step) {
        if (!is_array($step)) {
            throw new \UnexpectedValueException('Paired host steps must be YAML mappings.');
        }
        if (!array_key_exists('run', $step)) {
            continue;
        }
        if (!is_string($step['name'] ?? null) || !is_string($step['run'])) {
            throw new \UnexpectedValueException('Paired host command steps require names and shell scripts.');
        }
        $commands[$step['name']] = $step['run'];
    }

    return $commands;
}
