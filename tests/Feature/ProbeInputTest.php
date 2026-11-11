<?php

declare(strict_types=1);

use Infocyph\Foundation\Tests\Fixtures\ProbeInput;

it('resolves local probe inputs and new outputs without allowing remote wrappers or traversal', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-probe-input-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    try {
        expect(ProbeInput::path($directory . '/new/output.json'))->toBe($directory . '/new/output.json')
            ->and(ProbeInput::path(__FILE__))->toBe(__FILE__);
        foreach (['', "bad\0path", 'https://example.test/report', 'php://filter/resource=secret', '//remote/share', 'report?query', 'report#fragment', 'report&query', $directory . '/../outside'] as $invalid) {
            expect(fn() => ProbeInput::path($invalid))->toThrow(InvalidArgumentException::class);
        }
        expect(fn() => ProbeInput::autoload($directory . '/not-an-autoloader.php'))->toThrow(InvalidArgumentException::class);
        expect(ProbeInput::project($directory))->toBe($directory);
        $cwd = getcwd();
        ProbeInput::autoload(dirname(__DIR__, 2) . '/vendor/autoload.php');
        expect(getcwd())->toBe($cwd);
    } finally {
        rmdir($directory);
    }
});

it('rejects absolute paths and symlinks escaping every authorized local workspace', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-probe-link-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    try {
        expect(fn() => ProbeInput::path('/etc/passwd'))->toThrow(InvalidArgumentException::class);
        if (!symlink('/etc', $directory . '/escape')) {
            throw new RuntimeException('Unable to prepare the symlink escape fixture.');
        }
        expect(fn() => ProbeInput::path($directory . '/escape/passwd'))->toThrow(InvalidArgumentException::class)
            ->and(fn() => ProbeInput::path($directory . '/escape/new-file'))->toThrow(InvalidArgumentException::class);
    } finally {
        if (is_link($directory . '/escape')) {
            unlink($directory . '/escape');
        }
        rmdir($directory);
    }
});

it('validates CLI argument lists and fixed-host benchmark URLs', function (): void {
    expect(ProbeInput::arguments(['probe.php', 'one', 'two']))->toBe(['probe.php', 'one', 'two']);
    foreach ([null, 'probe.php', ['named' => 'value'], ['probe.php', 1]] as $arguments) {
        expect(fn() => ProbeInput::arguments($arguments))->toThrow(InvalidArgumentException::class);
    }
    foreach (['/', '/json', '/native', '/authenticated', '/auth', '/benchmark'] as $path) {
        expect(ProbeInput::loopbackHttp('http://127.0.0.1:18080' . $path))->toBe('http://127.0.0.1:18080' . $path);
    }
    foreach ([
        'http://127.0.0.1:80@evil.example/json', 'http://127.0.0.1:80@127.0.0.1/json',
        'http://localhost:80/json', 'https://127.0.0.1:80/json', 'http://127.0.0.1:0/json',
        'http://127.0.0.1:80/json?destination=elsewhere', 'http://127.0.0.1:80/json#fragment',
        'http://127.0.0.1:80/arbitrary', 'http://127.0.0.1/json',
    ] as $url) {
        expect(fn() => ProbeInput::loopbackHttp($url))->toThrow(InvalidArgumentException::class);
    }
});

it('rejects writable or shell-controlled source projects before bootstrapping code', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-probe-project-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    try {
        foreach ([';command', '`command`', '$(command)', "\ncommand", '&command', '|command', '%command%'] as $suffix) {
            expect(fn() => ProbeInput::project($directory . $suffix))->toThrow(InvalidArgumentException::class);
            expect(file_exists($directory . $suffix))->toBeFalse();
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($directory, 0777);
            expect(fn() => ProbeInput::project($directory))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        chmod($directory, 0700);
        rmdir($directory);
    }
});
