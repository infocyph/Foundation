<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;

it('keeps runtime token roots out of generated InterMix artifacts', function (string $driver): void {
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'foundation-epicrypt31-artifact-' . $driver . '-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0775, true);

    $environment = 'FOUNDATION_TEST_GENERATED_' . strtoupper($driver) . '_TOKEN_SECRET';
    $secret = 'generated-artifact-sentinel-' . bin2hex(random_bytes(32));
    $_ENV[$environment] = $secret;
    $_SERVER[$environment] = $secret;
    putenv($environment . '=' . $secret);

    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'local',
        ],
        '_config_cache' => false,
        'auth' => [
            'token_secret_environment' => $environment,
            'drivers' => ['tokens' => $driver],
        ],
        'security' => [
            'jwt' => [
                'algorithm' => 'HS256',
                'issuer' => 'generated.test',
                'audience' => 'generated-api',
                'maximum_lifetime_seconds' => 3600,
                'leeway_seconds' => 0,
            ],
        ],
    ];
    $artifact = $project . '/bootstrap/cache/cli.php';

    try {
        $report = new GeneratedRuntimeCompiler()->compile($config, RuntimeMode::Cli, $artifact);
        foreach ([
            $artifact,
            $artifact . '.meta.json',
            $artifact . '.foundation.json',
        ] as $path) {
            $contents = file_get_contents($path);
            expect($contents)->toBeString()->not->toContain($secret);
        }

        $runtime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Cli,
            $artifact,
            $report['metadata_sha256'],
            $report['digest'],
        );
        $services = $runtime->application->make(AuthServices::class);
        $now = time();
        $issued = $services->tokens()->issueAccessToken(new AccessTokenClaims(
            subjectId: 'account-1',
            actorId: null,
            issuedAt: $now,
            expiresAt: $now + 60,
            scopes: ['profile.read'],
        ));

        expect($issued->token)->toBeString()->not->toBe('');
    } finally {
        unset($_ENV[$environment], $_SERVER[$environment]);
        putenv($environment);
        foundationEpicrypt31ArtifactRemove($project);
    }
})->with(['simple', 'security']);

function foundationEpicrypt31ArtifactRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
