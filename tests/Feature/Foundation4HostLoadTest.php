<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('warms distinct reusable connections before counting measured requests', function (bool $authenticated): void {
    foundationHostLoadProbe('valid', function (Process $load, string $output) use ($authenticated): void {
        expect($load->getExitCode())->toBe(0, $load->getErrorOutput());
        expect($load->getErrorOutput())->toBe('');
        $result = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        expect($result['requests'])->toBe(100)
            ->and($result['successful'])->toBe(100)
            ->and($result['database_queries'])->toBe($authenticated ? 400 : 0)
            ->and($result['application_cache_hits'])->toBe($authenticated ? 100 : 0)
            ->and($result['connection_warmup']['successful'] ?? null)->toBe(10)
            ->and($result['measured_new_connections'] ?? null)->toBe(0)
            ->and($result['load_cpu_percent'])->toBeGreaterThanOrEqual(0)
            ->and(array_sum($result['worker_requests'] ?? []))->toBe($authenticated ? 100 : 0);
    }, $authenticated);
})->with([true, false]);

it('rejects invalid authenticated evidence during connection warmup', function (): void {
    foundationHostLoadProbe('invalid', function (Process $load, string $output): void {
        expect($load->getExitCode())->not->toBe(0)
            ->and($load->getErrorOutput())->toContain('Connection warm-up failed')
            ->and(file_exists($output))->toBeFalse();
    });
});

it('still counts and rejects failed measured responses after successful connection warmup', function (): void {
    foundationHostLoadProbe('measurement-invalid', function (Process $load, string $output): void {
        expect($load->getExitCode())->not->toBe(0);
        $result = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        expect($result['connection_warmup']['successful'])->toBe(10)
            ->and($result['successful'])->toBe(0)
            ->and($result['failed'])->toBe(100)
            ->and($load->getErrorOutput())->toContain('HTTP load failed');
    });
});

function foundationHostLoadProbe(string $mode, callable $assert, bool $authenticated = true): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve host-load probe port: ' . $message);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Cannot read host-load probe address.');
    }
    $root = dirname(__DIR__, 2);
    $output = sys_get_temp_dir() . '/foundation-host-load-' . bin2hex(random_bytes(8)) . '.json';
    $server = new Process([
        PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr',
        '-d', 'disable_functions=pcntl_fork,pcntl_waitpid,pcntl_signal,pcntl_async_signals',
        $root . '/tests/Fixtures/HostLoadProbe.php', $address, $mode,
    ], $root);
    $server->start();

    try {
        $ready = false;
        $probe = curl_init('http://' . $address . '/');
        curl_setopt_array($probe, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_HTTPHEADER => ['Connection: close'],
        ]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (curl_exec($probe) === '{"ok":true}' && curl_getinfo($probe, CURLINFO_HTTP_CODE) === 200) {
                $ready = true;
                break;
            }
            if (!$server->isRunning()) {
                throw new RuntimeException('Host-load probe exited: ' . $server->getErrorOutput());
            }
            usleep(10_000);
        }
        if (!$ready) {
            throw new RuntimeException('Host-load probe did not become ready.');
        }
        $load = new Process([
            PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr',
            $root . '/benchmarks/foundation4-host-load.php',
            'http://' . $address . '/', '100', '10', $output,
        ], $root, [
            'FOUNDATION_HOST_WORKLOAD_PROFILE' => $authenticated ? 'authenticated-read' : 'json-minimal',
            'FOUNDATION_HOST_MIN_SECONDS' => '0',
        ], timeout: 15);
        $load->run();
        $assert($load, $output);
    } finally {
        $server->stop(1);
        if (file_exists($output)) {
            unlink($output);
        }
    }
}
