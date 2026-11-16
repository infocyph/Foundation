<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires tagged releases to pass the complete security, consumer, migration and native-host gates', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $jobs = $workflow['jobs'];

    expect($jobs['release']['needs'] ?? [])->toContain(
        'phpforge',
        'production-consumer',
        'release-evidence',
        'runtime-hosts',
        'talkingbytes-mailpit',
        'talkingbytes-grpc',
        'historical-dependency-provenance',
        'cross-major-auth-rehearsal',
        'redis-cluster',
        'documentation',
    )
        ->and($jobs['release']['if'] ?? null)->toContain("startsWith(github.ref, 'refs/tags/')")
        ->not->toContain('always(', 'failure(', 'cancelled(');

    foreach (['phpforge', 'production-consumer', 'runtime-hosts', 'documentation'] as $job) {
        expect($jobs)->toHaveKey($job);
    }
    expect($jobs['phpforge']['uses'] ?? null)
        ->toBe('infocyph/phpforge/.github/workflows/security-standards.yml@a6232b6aefcc32b84b2fb94b8db8f5ac3b276d5d')
        ->and($jobs['release']['uses'] ?? null)
        ->toBe('infocyph/phpforge/.github/workflows/release.yml@7214b16487277181c177343d4e4163a9b5843f24');
});

it('checks real native Runwire serving on both supported PHP versions and Swoole drivers', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $host = $workflow['jobs']['runtime-hosts'] ?? null;
    $strategy = is_array($host) ? ($host['strategy'] ?? null) : null;
    $matrix = is_array($strategy) ? ($strategy['matrix'] ?? null) : null;
    $steps = is_array($host) ? ($host['steps'] ?? null) : null;
    if (!is_array($host) || !is_array($strategy) || !is_array($matrix) || !is_array($steps)) {
        throw new UnexpectedValueException('Native runtime job must declare a PHP/driver matrix and test steps.');
    }

    expect($matrix['php'] ?? null)->toBe(['8.4', '8.5'])
        ->and($matrix['extension'] ?? null)->toBe(['swoole', 'openswoole'])
        ->and($strategy['fail-fast'] ?? null)->toBeFalse()
        ->and($host['runs-on'] ?? null)->toBe('ubuntu-latest')
        ->and($host)->not->toHaveKey('continue-on-error');

    $scripts = [];
    foreach ($steps as $step) {
        if (!is_array($step)) {
            throw new UnexpectedValueException('Native runtime steps must be YAML mappings.');
        }
        if (isset($step['run'])) {
            if (!is_string($step['run'])) {
                throw new UnexpectedValueException('Native runtime step must use a literal shell script.');
            }
            $scripts[] = $step['run'];
        }
    }
    $commands = implode("\n", $scripts);
    expect($commands)->toContain(
        'RuntimeEnvironmentProbe',
        'RuntimeDriver::SWOOLE',
        'tests/Feature/ServeCommandIntegrationTest.php',
        'tests/Feature/WebReleaseRuntimeTest.php',
        '--fail-on-skipped',
    )
        ->not->toContain('|| true', 'continue-on-error');
});

it('keeps the retired full throughput certification out of the release workflow without faking a pass', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = foundationReleaseWorkflow($root . '/.github/workflows/security-standards.yml');

    expect($workflow['jobs'])->not->toHaveKey('source-certification');
    expect(is_file($root . '/.github/workflows/foundation4-host-certification.yml'))->toBeFalse();

    $notes = file_get_contents($root . '/docs/foundation-4.0-release-notes.md');
    if (!is_string($notes)) {
        throw new UnexpectedValueException('Release notes are required.');
    }
    expect($notes)->toContain(
        'The maintainer elected to **stop further sustained-host performance testing**',
        '**both comparison jobs failed**',
        '**explicit release-scope decision, not a repaired or passing',
    );
});

/**
 * Parse actual GitHub Actions release triggers and named jobs.
 *
 * @return array{on:array<string,mixed>,jobs:array<string,array<string,mixed>>}
 * @throws \UnexpectedValueException
 * @throws \Symfony\Component\Yaml\Exception\ParseException
 */
function foundationReleaseWorkflow(string $path): array
{
    $document = Yaml::parseFile($path);
    if (!is_array($document) || !is_array($document['on'] ?? null) || !is_array($document['jobs'] ?? null)) {
        throw new UnexpectedValueException('Release workflow must define event triggers and a jobs map.');
    }

    $jobs = [];
    foreach ($document['jobs'] as $name => $job) {
        if (!is_string($name) || !is_array($job)) {
            throw new UnexpectedValueException('Release workflow jobs must be named mappings.');
        }
        $jobs[$name] = foundationStringMap($job);
    }

    return ['on' => foundationStringMap($document['on']), 'jobs' => $jobs];
}
