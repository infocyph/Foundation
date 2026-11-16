<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

/** Local-only CLI inputs for isolated fixtures and benchmark subprocesses. */
final class ProbeInput
{
    /**
     * @param list<string> $arguments
     * @throws \InvalidArgumentException
     */
    public static function argument(array $arguments, int $index): string
    {
        return $arguments[$index] ?? throw new \InvalidArgumentException('Missing required CLI fixture argument.');
    }

    /**
     * Validate the PHP CLI argument boundary, including embedded runners that
     * may populate the server array themselves.
     * Invalid values are rejected here with an argument validation exception.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     *
     * @param array<array-key, mixed>|string|null $input
     */
    public static function arguments(array|string|null $input): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            throw new \InvalidArgumentException('CLI arguments must be a list of strings.');
        }
        $arguments = [];
        foreach ($input as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('CLI arguments must be strings.');
            }
            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * Read untrusted server metadata before entering the typed CLI contract.
     *
     * @param array<array-key, mixed> $server
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    public static function fromServer(array $server): array
    {
        $input = $server['argv'] ?? null;
        if (!is_array($input) && !is_string($input) && $input !== null) {
            throw new \InvalidArgumentException('CLI arguments must be a list of strings.');
        }

        return self::arguments($input);
    }

    /**
     * Bootstrap the selected Composer graph in a fresh fixture process.
     * The temporary CWD is restored before the probe performs any work.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function autoload(string $input): void
    {
        $path = self::path($input);
        if (basename($path) !== 'autoload.php' || basename(dirname($path)) !== 'vendor' || !is_file($path)) {
            throw new \InvalidArgumentException('Probe bootstrap requires a Composer vendor/autoload.php.');
        }
        $cwd = getcwd();
        if ($cwd === false || !chdir(dirname($path, 2))) {
            throw new \RuntimeException('Unable to enter the selected Composer project.');
        }

        try {
            require 'vendor/autoload.php';
        } finally {
            if (!chdir($cwd)) {
                throw new \RuntimeException('Unable to restore the probe working directory.');
            }
        }
    }

    /**
     * Rebuild a fixed loopback URL; it cannot select a remote host or file URI.
     *
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape file
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     * @throws \InvalidArgumentException
     */
    public static function loopbackHttp(string $input): string
    {
        $parts = parse_url($input);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || !isset($parts['port']) || $parts['port'] < 1
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('Load benchmarks require a loopback HTTP URL without credentials or queries.');
        }
        $path = match ($parts['path'] ?? '/') {
            '/' => '/',
            '/json' => '/json',
            '/auth' => '/auth',
            '/native' => '/native',
            '/authenticated' => '/authenticated',
            '/benchmark' => '/benchmark',
            default => throw new \InvalidArgumentException('Unrecognized host benchmark endpoint.'),
        };

        return 'http://127.0.0.1:' . $parts['port'] . $path;
    }

    /**
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function map(mixed $input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Probe configuration must be a string-keyed map.');
        }
        $map = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Probe configuration keys must be strings.');
            }
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * Resolve existing inputs or new output leaves inside the checkout,
     * consumer working directory or temporary test workspace. Stream wrappers,
     * traversal segments and symlink escapes cannot cross those authorities.
     *
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     * @throws \InvalidArgumentException
     */
    public static function path(string $input): string
    {
        if ($input === '' || str_contains($input, "\0") || str_contains($input, '://') || strpbrk($input, '?#&') !== false) {
            throw new \InvalidArgumentException('Probe paths must be local filesystem paths.');
        }
        $input = str_replace('\\', '/', $input);
        if (str_starts_with($input, '//') || in_array('..', explode('/', $input), true)) {
            throw new \InvalidArgumentException('Probe paths cannot contain parent traversal.');
        }
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \InvalidArgumentException('Probe working directory is unavailable.');
        }
        $absolute = str_starts_with($input, '/') || preg_match('/\A[A-Za-z]:\//D', $input) === 1
            ? $input : $cwd . '/' . $input;
        $canonical = self::canonical($absolute);
        if (self::authorized($canonical, $cwd)) {
            return $canonical;
        }

        throw new \InvalidArgumentException('Probe path is outside the selected local workspaces.');
    }

    /**
     * An operator-selected private fixture project is a source-code authority,
     * unlike an arbitrary data-file argument. Production request input never
     * passes through this development-only bootstrap contract.
     *
     * @psalm-taint-escape include
     * @psalm-taint-escape shell
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function project(string $input): string
    {
        $path = self::path($input);
        if (strspn($path, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_ .:/\\~-') !== strlen($path)) {
            throw new \InvalidArgumentException('Probe source projects cannot contain shell control characters.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare the private probe project.');
        }
        $permissions = fileperms($path);
        if ($permissions === false || (PHP_OS_FAMILY !== 'Windows' && ($permissions & 0002) !== 0)) {
            throw new \InvalidArgumentException('Probe source projects must not be world writable.');
        }

        return $path;
    }

    private static function authorized(string $canonical, string $cwd): bool
    {
        $runner = getenv('RUNNER_TEMP');
        $roots = [dirname(__DIR__, 2), $cwd, sys_get_temp_dir()];
        if (is_string($runner) && $runner !== '') {
            $roots[] = $runner;
        }
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved !== false && self::contained($resolved, $canonical)) {
                return true;
            }
        }

        return false;
    }

    /** @throws \InvalidArgumentException */
    private static function canonical(string $path): string
    {
        $suffix = [];
        $existing = $path;
        while (!file_exists($existing) && !is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                throw new \InvalidArgumentException('Probe path has no existing filesystem ancestor.');
            }
            array_unshift($suffix, basename($existing));
            $existing = $parent;
        }
        $resolved = realpath($existing);
        if ($resolved === false) {
            throw new \InvalidArgumentException('Probe path cannot resolve its existing ancestor.');
        }

        return $resolved . ($suffix === [] ? '' : '/' . implode('/', $suffix));
    }

    private static function contained(string $root, string $candidate): bool
    {
        $root = str_replace('\\', '/', $root);
        $candidate = str_replace('\\', '/', $candidate);
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }

        return $candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/');
    }
}
