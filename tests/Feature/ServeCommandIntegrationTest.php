<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Tests\Fixtures\ServeProbe;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;

it('serves real requests with isolated contexts and recovers after a failed request', function (bool $direct, bool $portable, int $workers, RuntimeDriver $runtime = RuntimeDriver::AUTO): void {
    $project = sys_get_temp_dir() . '/foundation-serve-' . bin2hex(random_bytes(6));
    foreach (['bootstrap', 'routes', 'storage', 'public'] as $directory) {
        mkdir($project . '/' . $directory, 0700, true);
    }
    $config = [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false, 'capabilities' => []],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    file_put_contents($project . '/bootstrap/providers.php', "<?php\nreturn ['common' => []];\n");
    file_put_contents($project . '/routes/workers.php', "<?php\nreturn [];\n");
    file_put_contents($project . '/routes/web.php', '<?php' . PHP_EOL
        . '\Infocyph\Webrick\Router\Facade\Router::get("/probe", [' . ServeProbe::class . '::class, "read"]);' . PHP_EOL
        . '\Infocyph\Webrick\Router\Facade\Router::get("/failure", [' . ServeProbe::class . '::class, "failure"]);' . PHP_EOL);
    $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
    $source = '<?php' . PHP_EOL . 'require ' . $autoload . ';' . PHP_EOL;
    $process = null;

    try {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve serving test port: ' . $errorMessage);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address)) {
            throw new RuntimeException('Unable to read serving test address.');
        }
        $port = substr($address, strrpos($address, ':') + 1);
        $command = [PHP_BINARY];
        if ($portable) {
            array_push($command, '-d', 'disable_functions=pcntl_fork,pcntl_waitpid,pcntl_signal,pcntl_async_signals');
        }

        if ($direct) {
            $root = $project . '/storage/releases';
            $release = new FoundationReleaseCompiler()->buildAndActivate($config, $root);
            file_put_contents($project . '/public/index.php', $source
                . '$release = new \Infocyph\Foundation\Release\FoundationReleaseBootstrap('
                . var_export($root, true) . ', ' . var_export($release['manifest_sha256'], true) . ');' . PHP_EOL
                . '$release->web()->server->handle();' . PHP_EOL);
            array_push($command, '-S', $address, '-t', $project . '/public', $project . '/public/index.php');
        } else {
            file_put_contents($project . '/infbyte', $source
                . 'exit(\Infocyph\Foundation\Command\CommandDispatcher::project('
                . var_export($config, true) . ')->run($argv));' . PHP_EOL);
            array_push($command, $project . '/infbyte', 'serve', '--port=' . $port, '--workers=' . $workers);
            if ($runtime !== RuntimeDriver::AUTO) {
                $command[] = '--runtime=' . $runtime->value;
            }
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $project . '/stdout.log', 'w'],
            2 => ['file', $project . '/stderr.log', 'w'],
        ], $pipes, $project);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start serving test process.');
        }
        fclose($pipes[0]);
        $processPid = proc_get_status($process)['pid'];
        $prefork = !$portable && new RuntimeEnvironmentProbe()->probe()->nativePreforkEligible();
        $hostWorkers = $runtime === RuntimeDriver::SWOOLE ? $workers > 1 : $prefork;
        if (!$direct && !$hostWorkers && $workers > 1) {
            $exit = proc_close($process);
            $process = null;
            expect($exit)->toBe(1)
                ->and(file_get_contents($project . '/stderr.log'))->toContain('Portable Runwire serving supports at most one worker');

            return;
        }
        foundationServeWait($process, $address, $project);

        $responses = [];
        foreach (['/probe', '/failure', '/probe'] as $path) {
            $body = file_get_contents('http://' . $address . $path, false, stream_context_create([
                'http' => ['ignore_errors' => true, 'timeout' => 3, 'header' => "Connection: close\r\n"],
            ]));
            $headers = http_get_last_response_headers();
            expect($body)->toBeString()->not->toContain('serve-probe-private-error');
            if ($path === '/failure') {
                expect($headers[0] ?? '')->toContain('500');
                continue;
            }
            expect($headers[0] ?? '')->toContain('200');
            $response = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            expect($response['pid'])->toBeGreaterThan(0);
            if ($direct) {
                expect($response['runtime_pid'])->toBeNull()
                    ->and($response['request_id'])->toBeNull();
            } else {
                expect($response['runtime_pid'])->toBe($response['pid'])
                    ->and($response['request_id'])->toBeString()->not->toBeEmpty();
                if ($hostWorkers) {
                    expect($response['pid'])->not->toBe($processPid);
                } elseif ($runtime !== RuntimeDriver::SWOOLE) {
                    expect($response['pid'])->toBe($processPid);
                }
            }
            $responses[] = $response;
        }
        expect($responses)->toHaveCount(2);
        if (!$direct) {
            expect($responses[0]['request_id'])->not->toBe($responses[1]['request_id']);
        }
        if ($runtime === RuntimeDriver::SWOOLE) {
            $responses = [];
            $deadline = microtime(true) + 5;
            do {
                array_push($responses, ...foundationServeBurst($address));
                if (count(array_unique(array_column($responses, 'pid'))) === $workers) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            foreach ($responses as $response) {
                expect($response['runtime_pid'])->toBe($response['pid'])
                    ->and($response['worker_slot'])->toBeIn(range(0, $workers - 1));
            }
            expect(array_unique(array_column($responses, 'pid')))->toHaveCount($workers)
                ->and(array_unique(array_column($responses, 'worker_slot')))->toHaveCount($workers)
                ->and(array_unique(array_column($responses, 'request_id')))->toHaveCount(count($responses));
            if ($hostWorkers) {
                expect(array_column($responses, 'pid'))->not->toContain($processPid);
            }
        }
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        foundationResetWebrickProductionRegistries();
        BenchmarkSupport::removeDirectory($project);
    }
})->with([
    'Runwire auto' => [false, false, 1],
    'Runwire configured pool' => [false, false, 2],
    'Runwire portable' => [false, true, 1],
    'Runwire portable rejects pooled workers' => [false, true, 2],
    'ordinary index without serve' => [true, false, 1],
    ...(new RuntimeEnvironmentProbe()->probe()->isAvailable(RuntimeDriver::SWOOLE) ? [
        'Runwire Swoole one worker' => [false, false, 1, RuntimeDriver::SWOOLE],
        'Runwire Swoole two workers' => [false, false, 2, RuntimeDriver::SWOOLE],
    ] : []),
]);

/** @return list<array<string, mixed>> */
function foundationServeBurst(string $address): array
{
    $multi = curl_multi_init();
    $handles = [];
    try {
        for ($index = 0; $index < 16; ++$index) {
            $handle = curl_init('http://' . $address . '/probe');
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Connection: close'],
                CURLOPT_FRESH_CONNECT => true,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            expect($status)->toBe(CURLM_OK);
            if ($running > 0 && curl_multi_select($multi, 0.1) === -1) {
                usleep(1_000);
            }
        } while ($running > 0);

        $responses = [];
        foreach ($handles as $handle) {
            expect(curl_errno($handle))->toBe(0)
                ->and(curl_getinfo($handle, CURLINFO_RESPONSE_CODE))->toBe(200);
            $responses[] = json_decode(curl_multi_getcontent($handle), true, flags: JSON_THROW_ON_ERROR);
        }

        return $responses;
    } finally {
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    }
}

/** @param resource $process */
function foundationServeWait(mixed $process, string $address, string $project): void
{
    $deadline = microtime(true) + 15;
    do {
        $connection = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (is_resource($connection)) {
            $connected = stream_socket_get_name($connection, true) !== false;
            fclose($connection);
            if ($connected) {
                return;
            }
        }
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Serving did not become ready: '
        . file_get_contents($project . '/stdout.log') . file_get_contents($project . '/stderr.log'));
}
