<?php

declare(strict_types=1);

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

it('classifies native library edges while rejecting framework-to-application dependencies', function (): void {
    $root = dirname(__DIR__, 2);
    $tool = $root . '/vendor/bin/deptrac';
    expect($tool)->toBeFile();

    $directory = sys_get_temp_dir() . '/foundation-deptrac-' . bin2hex(random_bytes(7));
    mkdir($directory . '/src', 0700, true);

    try {
        file_put_contents(
            $directory . '/src/Contracts.php',
            "<?php\nnamespace Infocyph\\Infbyte;\ninterface AppContract {}\n"
            . "namespace Infocyph\\Runwire;\ninterface NativeContract {}\n",
        );
        file_put_contents(
            $directory . '/src/Framework.php',
            "<?php\nnamespace Infocyph\\Foundation\\Probe;\n"
            . "final class Probe implements \\Infocyph\\Infbyte\\AppContract, \\Infocyph\\Runwire\\NativeContract {}\n",
        );
        $original = file_get_contents($root . '/deptrac.yaml');
        expect($original)->toBeString()->toContain('    - ./src');
        if ($original === false) {
            throw new \RuntimeException('Unable to load Deptrac policy fixture.');
        }

        file_put_contents(
            $directory . '/deptrac.yaml',
            str_replace('    - ./src', '    - ' . $directory . '/src', $original),
        );
        $result = new ProcessRunner()->run([
            PHP_BINARY,
            $tool,
            '--no-cache',
            'analyse',
            '--config-file=' . $directory . '/deptrac.yaml',
            '--no-progress',
            '--formatter=table',
        ], new ProcessOptions(cwd: $root, timeoutSeconds: 30.0, handleSignals: false));

        expect($result->timedOut)->toBeFalse()
            ->and($result->successful())->toBeFalse()
            ->and($result->stdout . $result->stderr)->toContain('AppContract');
    } finally {
        foreach (['deptrac.yaml', 'src/Framework.php', 'src/Contracts.php'] as $path) {
            $file = $directory . '/' . $path;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory . '/src');
        rmdir($directory);
    }
});
