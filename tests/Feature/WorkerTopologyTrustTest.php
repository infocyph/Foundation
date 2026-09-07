<?php

declare(strict_types=1);

use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\Foundation\Worker\WorkerTopology;

final class FoundationWorkerTopologyTrustProvider implements WorkerProvider
{
    public function run(WorkerRuntime $runtime): int
    {
        unset($runtime);

        return 0;
    }
}

it('rejects missing or tampered generated worker topology artifacts', function (): void {
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-worker-topology-trust-' . bin2hex(random_bytes(5));
    $routes = $project . '/routes';
    $artifact = $project . '/generation/worker/providers.php';
    mkdir($routes, 0777, true);
    file_put_contents(
        $routes . '/workers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['trusted' => FoundationWorkerTopologyTrustProvider::class];\n",
    );

    try {
        $topology = new WorkerTopology();
        $compiled = $topology->compile(
            ['app' => ['base_path' => $project]],
            $artifact,
        );

        expect($topology->load($artifact, $compiled['sha256']))
            ->toHaveKey('trusted');

        file_put_contents($artifact, "<?php\n\nreturn ['format' => 1, 'providers' => []];\n");
        expect(fn() => $topology->load($artifact, $compiled['sha256']))
            ->toThrow(RuntimeException::class, 'Foundation worker topology trust identity mismatch.');

        unlink($artifact);
        expect(fn() => $topology->load($artifact, $compiled['sha256']))
            ->toThrow(RuntimeException::class, 'Foundation worker topology is not readable');
    } finally {
        foundationWorkerTopologyTrustRemove($project);
    }
});

function foundationWorkerTopologyTrustRemove(string $directory): void
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
