<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires tagged Foundation releases to pass quality and production consumers', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $jobs = $workflow['jobs'];

    expect($jobs['release']['needs'] ?? [])->toContain('phpforge', 'production-consumer', 'release-evidence', 'runtime-hosts')
        ->and($jobs['release']['if'])->toContain("startsWith(github.ref, 'refs/tags/')")
        ->not->toContain('always(', 'failure(', 'cancelled(');

    foreach (['phpforge', 'production-consumer'] as $job) {
        expect($jobs[$job]['if'] ?? null)->toBeNull();
    }

    expect($jobs['runtime-hosts']['uses'])->toBe('./.github/workflows/foundation4-host-certification.yml')
        ->and($jobs['runtime-hosts']['if'])->toContain("startsWith(github.ref, 'refs/tags/')");
    $hosts = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    expect($hosts['on'])->toHaveKey('workflow_call');
});
