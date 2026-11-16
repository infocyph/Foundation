<?php

declare(strict_types=1);

require_once __DIR__ . '/ProbeInput.php';
$argv = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::fromServer($_SERVER);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseManifest;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Webrick\Request\Request;

if (count($argv) !== 5) {
    throw new InvalidArgumentException('Expected stage, common project, version-specific autoloader, and report directory.');
}

$stage = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::argument($argv, 1);
$project = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::argument($argv, 2);
$autoload = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::argument($argv, 3);
$reports = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::argument($argv, 4);
if (!in_array($stage, ['old-build', 'new-build', 'old-rollback', 'new-rollforward'], true)) {
    throw new InvalidArgumentException('Unknown cross-major generated-release stage.');
}

$project = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::project($project);
$reports = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::path($reports);
\Infocyph\Foundation\Tests\Fixtures\ProbeInput::autoload($autoload);

$root = $project . '/storage/releases';
$generation = str_starts_with($stage, 'old-') ? 'original-f3' : 'candidate-f4';
$isBuild = str_ends_with($stage, '-build');
$config = [
    'app' => [
        'base_path' => $project,
        'env' => 'production',
        'debug' => false,
        'topology' => 'single_node',
    ],
    '_config_cache' => false,
    'router' => [
        'files' => ['web.php'],
        'matcher' => 'fused',
        'middleware' => ['globals' => ['pre' => [], 'post' => []]],
    ],
];

if ($isBuild) {
    foreach ([$project . '/bootstrap', $project . '/routes', $project . '/storage'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot prepare release fixture directory.');
        }
    }

    if (!is_file($project . '/bootstrap/providers.php')) {
        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['common' => [], 'web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []];\n",
        );
        file_put_contents($project . '/routes/web.php', "<?php\n\ndeclare(strict_types=1);\n");
        file_put_contents($project . '/routes/workers.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
    }

    $result = new FoundationReleaseCompiler()->buildAndActivate(
        $config,
        $root,
        ['web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []],
        $generation,
    );

    if (($result['generation']) !== $generation) {
        throw new RuntimeException('Compiled release generation is incorrect.');
    }
} else {
    new ActiveGeneration()->activate($root, $generation);
}

$active = new ActiveGeneration()->current($root);
if ($active['generation'] !== $generation) {
    throw new RuntimeException('Versioned source did not activate its expected generation.');
}

$manifestPath = $active['manifest'];
$manifest = FoundationReleaseManifest::load($manifestPath);
$fingerprint = FoundationReleaseManifest::dependencyFingerprint();
if (($manifest['dependency_fingerprint'] ?? null) !== $fingerprint) {
    throw new RuntimeException('Generated release dependency identity differs from its installed graph.');
}

$sha = hash_file('sha256', $manifestPath);
if (!is_string($sha) || strlen($sha) !== 64) {
    throw new RuntimeException('Generated release manifest has no valid SHA256 identity.');
}

$generationPath = $root . '/generations/' . $generation;
foreach (['web', 'cli', 'worker', 'scheduler'] as $mode) {
    $container = $generationPath . '/' . $mode . '/container.php';
    if (!is_file($container)) {
        throw new RuntimeException('Generated ' . $mode . ' release container is missing.');
    }
}
foreach (['web/router.php', 'web/release.json', 'worker/providers.php'] as $relativePath) {
    if (!is_file($generationPath . '/' . $relativePath)) {
        throw new RuntimeException('Generated release artifact is missing: ' . $relativePath);
    }
}

$loader = new FoundationReleaseRuntime();
$web = $loader->webPrevalidated($config, $root, $sha);
$response = $web->kernel->handle(Request::fake(headers: ['Host' => 'release.test'], uri: 'https://release.test/not-configured'));
if ($response->getStatusCode() !== 404) {
    throw new RuntimeException('The versioned web runtime did not return the expected empty-router response.');
}

foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $runtime) {
    $boot = $loader->nonWebPrevalidated($config, $runtime, $root, $sha);
    if ($boot->application->runtimeMode() !== $runtime) {
        throw new RuntimeException('Generated non-web runtime identity differs from requested mode.');
    }
}

$referencePath = $reports . '/' . (str_starts_with($stage, 'old-') ? 'old-build' : 'new-build') . '.json';
if (!$isBuild) {
    $reference = json_decode((string) file_get_contents($referencePath), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($reference)) {
        throw new \UnexpectedValueException('Expected a structured rehearsal record.');
    }
    if ($reference['dependency_fingerprint'] !== $fingerprint
        || $reference['manifest_sha256'] !== $sha
    ) {
        throw new RuntimeException('Restored release identity changed after switching sources and dependencies.');
    }
} elseif ($stage === 'new-build') {
    $prior = json_decode((string) file_get_contents($reports . '/old-build.json'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($prior)) {
        throw new \UnexpectedValueException('Expected a structured rehearsal record.');
    }
    if ($prior['dependency_fingerprint'] === $fingerprint) {
        throw new RuntimeException('Old and current dependency graphs did not actually change.');
    }
}

$report = [
    'stage' => $stage,
    'source_file' => (new ReflectionClass(FoundationReleaseCompiler::class))->getFileName(),
    'generation' => $generation,
    'manifest_sha256' => $sha,
    'dependency_fingerprint' => $fingerprint,
    'web_status' => $response->getStatusCode(),
    'generated_runtimes' => ['web', 'cli', 'worker', 'scheduler'],
];
if (file_put_contents($reports . '/' . $stage . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
    throw new RuntimeException('Could not save attested cross-major release generation evidence.');
}
fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
