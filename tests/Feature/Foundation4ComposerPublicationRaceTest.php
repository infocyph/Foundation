<?php

declare(strict_types=1);

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;

it('rejects a real Composer downgrade occurring while feature publication is staged and permits a fresh retry', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-composer-publication-' . bin2hex(random_bytes(6));
    mkdir($directory . '/config', 0700, true);
    $runner = new ProcessRunner();
    // Local metapackages exercise Composer's actual root/lock/installed writes.
    // Compatibility with real published distributions is covered by no-dev consumers.
    file_put_contents($directory . '/composer.json', json_encode([
        'name' => 'test/publication-race',
        'repositories' => [
            ['type' => 'package', 'package' => [
                ['name' => 'infocyph/dblayer', 'version' => '6.1.2', 'type' => 'metapackage'],
                ['name' => 'infocyph/dblayer', 'version' => '5.9.0', 'type' => 'metapackage'],
            ]],
            ['packagist.org' => false],
        ],
        'require' => ['infocyph/dblayer' => '6.1.2'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/config/database.php', '<?php return [];');
    $original = '<?php return ' . var_export(['features' => [], 'application_policy' => str_repeat('x', 16 * 1024 * 1024)], true) . ';';
    file_put_contents($directory . '/config/modules.php', $original);
    $process = null;
    $pipes = [];
    $stopped = false;
    $pid = null;

    try {
        $installed = $runner->run(['composer', 'update', '--no-interaction', '--no-plugins', '--no-scripts'], new ProcessOptions(cwd: $directory, timeoutSeconds: 30));
        expect($installed->successful())->toBeTrue();
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/module_activation_race_worker.php', $directory,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start feature activation process.');
        }
        fclose($pipes[0]);
        AuthContentionFixture::waitFor($directory . '/ready');
        $pid = proc_get_status($process)['pid'];
        touch($directory . '/start');
        $until = microtime(true) + 5;
        do {
            $staged = glob($directory . '/config/.foundation-modules-*');
            if ($staged !== []) {
                $stopped = posix_kill($pid, SIGSTOP);
                break;
            }
            usleep(500);
        } while (microtime(true) < $until);
        expect($stopped)->toBeTrue();
        $changed = $runner->run([
            'composer', 'require', 'infocyph/dblayer:5.9.0', '--no-interaction', '--no-plugins', '--no-scripts',
        ], new ProcessOptions(cwd: $directory, timeoutSeconds: 30));
        expect($changed->successful())->toBeTrue();
        posix_kill($pid, SIGCONT);
        $stopped = false;
        AuthContentionFixture::waitFor($directory . '/activation-result.json');
        $error = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $exit = proc_close($process);
        $process = null;
        expect($exit)->toBe(0)->and($error)->toBe('');
        $result = json_decode((string) file_get_contents($directory . '/activation-result.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($result)->toHaveKey('rejected')
            ->and($result['rejected'])->toContain('Composer metadata changed')
            ->and(file_get_contents($directory . '/config/modules.php'))->toBe($original)
            ->and(glob($directory . '/config/.foundation-modules-*'))->toBe([]);

        $repaired = $runner->run([
            'composer', 'require', 'infocyph/dblayer:6.1.2', '--no-interaction', '--no-plugins', '--no-scripts',
        ], new ProcessOptions(cwd: $directory, timeoutSeconds: 30));
        expect($repaired->successful())->toBeTrue();
        $app = \Infocyph\Foundation\Foundation::cli(['base_path' => $directory, '_config_cache' => false]);
        $context = new \Infocyph\Foundation\Module\ModuleFeatureCommandSnapshot($app)->capture();
        new \Infocyph\Foundation\Module\ModuleActivationManager($app)->applyFeatures('enable', ['data.database'], $context);
        $config = require $directory . '/config/modules.php';
        expect($config['features'])->toBe(['data.database' => true])
            ->and($config['application_policy'])->toBe(str_repeat('x', 16 * 1024 * 1024));
        // A removal plan read in another process must not survive a later enable.
        $snapshot = new \Infocyph\Foundation\Module\ModuleFeatureCommandSnapshot($app);
        $activation = new \Infocyph\Foundation\Module\ModuleActivationManager($app);
        $activation->applyFeatures('disable', ['data.database'], $snapshot->capture());
        foreach (['ready', 'start', 'activation-result.json'] as $file) {
            unlink($directory . '/' . $file);
        }
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/module_activation_race_worker.php', $directory, 'remove',
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Composer removal process.');
        }
        fclose($pipes[0]);
        AuthContentionFixture::waitFor($directory . '/ready');
        $activation->applyFeatures('enable', ['data.database'], $snapshot->capture());
        $composerBefore = file_get_contents($directory . '/composer.json');
        touch($directory . '/start');
        AuthContentionFixture::waitFor($directory . '/activation-result.json');
        $error = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $exit = proc_close($process);
        $process = null;
        $result = json_decode((string) file_get_contents($directory . '/activation-result.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(0)->and($error)->toBe('')
            ->and($result['rejected'] ?? '')->toContain('Feature configuration changed')
            ->and(file_get_contents($directory . '/composer.json'))->toBe($composerBefore)
            ->and((require $directory . '/config/modules.php')['features'])->toBe(['data.database' => true]);
    } finally {
        if ($stopped && is_int($pid)) {
            posix_kill($pid, SIGCONT);
        }
        if (is_resource($process)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
});
