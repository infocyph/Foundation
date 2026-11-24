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
        'risk-provider-wire',
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
        ->toBe('infocyph/phpforge/.github/workflows/security-standards.yml@main')
        ->and($jobs['phpforge']['with'] ?? [])->not->toHaveKey('php_versions')
        ->and($jobs['release']['uses'] ?? null)
        ->toBe('infocyph/phpforge/.github/workflows/release.yml@main');
});

it('uses named historical source branches instead of hardcoded revisions', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    foreach (['historical-dependency-provenance', 'cross-major-auth-rehearsal'] as $name) {
        $steps = $workflow['jobs'][$name]['steps'] ?? null;
        \PHPUnit\Framework\Assert::assertIsArray($steps);
        $references = [];
        foreach (array_keys($steps) as $index) {
            $step = foundationStringMap($steps[$index]);
            $with = foundationStringMap($step['with'] ?? []);
            if (($with['path'] ?? null) === 'historical') {
                $references[] = foundationFixtureString($with['ref'] ?? null);
            }
        }
        expect($references)->toBe(['archive/foundation-3']);
    }
});

it('checks real native Runwire serving on the supported PHP versions and Swoole drivers', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $host = $workflow['jobs']['runtime-hosts'] ?? null;
    $strategy = is_array($host) ? ($host['strategy'] ?? null) : null;
    $matrix = is_array($strategy) ? ($strategy['matrix'] ?? null) : null;
    $steps = is_array($host) ? ($host['steps'] ?? null) : null;
    if (!is_array($host) || !is_array($strategy) || !is_array($matrix) || !is_array($steps)) {
        throw new UnexpectedValueException('Native runtime job must declare a PHP/driver matrix and test steps.');
    }

    expect($matrix['php'] ?? null)->toBe(['8.4', '8.5', '8.6'])
        ->and($matrix['extension'] ?? null)->toBe(['swoole', 'openswoole'])
        ->and($matrix['exclude'] ?? null)->toBe([['php' => '8.6', 'extension' => 'swoole']])
        ->and($strategy['fail-fast'] ?? null)->toBeFalse()
        ->and($host['runs-on'] ?? null)->toBe('ubuntu-latest')
        ->and($host)->not->toHaveKey('continue-on-error');

    $scripts = [];
    foreach (array_keys($steps) as $index) {
        $step = foundationStringMap($steps[$index]);
        if (isset($step['run'])) {
            if (!is_string($step['run'])) {
                throw new UnexpectedValueException('Native runtime step must use a literal shell script.');
            }
            $scripts[] = $step['run'];
        }
    }
    $commands = implode("\n", $scripts);
    expect($commands)->toContain(
        'extension_loaded($extension)',
        'RuntimeEnvironmentProbe',
        'RuntimeDriver::SWOOLE',
        'tests/Feature/ServeCommandIntegrationTest.php',
        'tests/Feature/WebReleaseRuntimeTest.php',
        '--fail-on-skipped',
    )
        ->not->toContain('|| true', 'continue-on-error');
});

it('requires real native gRPC on the qualified PHP versions without ignoring failures', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $job = foundationStringMap($workflow['jobs']['talkingbytes-grpc'] ?? null);
    $strategy = foundationStringMap($job['strategy'] ?? null);
    $matrix = foundationStringMap($strategy['matrix'] ?? null);
    expect($matrix['php'] ?? null)->toBe(['8.4', '8.5', '8.6'])
        ->and($strategy['fail-fast'] ?? null)->toBeFalse()
        ->and($job)->not->toHaveKey('continue-on-error');
    expect($job['steps'] ?? null)->toBeArray();
    $encodedSteps = json_encode($job['steps'], JSON_THROW_ON_ERROR);
    expect($encodedSteps)->toContain(
        'none, grpc, curl, mbstring, intl, sodium, pdo, pdo_sqlite, dom, simplexml, xml, xmlreader, xmlwriter, tokenizer, ctype, fileinfo, iconv, phar, openssl, session, sockets, pcntl, posix, zip',
        'Construct a private PHP configuration for native gRPC',
        'PHP_INI_SCAN_DIR',
        'php_ini_loaded_file()',
        'php_ini_scanned_files()',
        'Private PHP INI was not inherited',
        'Unexpected shared OPcache extension',
        'opcache.enable_cli=0',
        'opcache.jit_buffer_size=0',
        'Unexpected unrelated PHP extension',
        'composer check-platform-reqs',
        'Extension-only native gRPC roundtrip',
        'run_native_probe -r',
        'Standalone native gRPC roundtrip',
        'talkingbytes_grpc_probe.php',
    )->not->toContain('continue-on-error', 'ignore-platform-req');
});

it('requires the actual risk provider wire qualification on every supported PHP version', function (): void {
    $workflow = foundationReleaseWorkflow(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $job = foundationStringMap($workflow['jobs']['risk-provider-wire'] ?? null);
    $strategy = foundationStringMap($job['strategy'] ?? null);
    $matrix = foundationStringMap($strategy['matrix'] ?? null);
    expect($matrix['php'] ?? null)->toBe(['8.4', '8.5', '8.6'])
        ->and($strategy['fail-fast'] ?? null)->toBeFalse()
        ->and($job)->not->toHaveKey('continue-on-error');
    expect(json_encode($job['steps'], JSON_THROW_ON_ERROR))
        ->toContain('sudo unshare --net', 'risk_provider_qualification.sh')
        ->not->toContain('ignore-platform-req', 'continue-on-error');
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
