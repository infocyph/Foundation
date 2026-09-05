<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Release\FoundationReleaseManifest;
use Infocyph\Foundation\Runtime\LoadedReleaseGeneration;
use Infocyph\Foundation\Support\ValueNormalizer;

/** Builds and loads the generation-owned provider-worker topology. */
final class WorkerTopology
{
    private const int FORMAT = 1;

    /**
     * @param array<string,mixed> $config
     * @return array{path:string,sha256:string,providers:int}
     */
    public function compile(
        array $config,
        string $artifactPath,
        string $routes = 'routes/workers.php',
    ): array {
        $repository = new ConfigLoader()->load($config);
        $basePath = ValueNormalizer::string(
            $repository->get('app.base_path'),
            getcwd() ?: dirname(__DIR__, 2),
        );
        $routePath = $this->path($basePath, $routes);
        $definitions = $this->source(
            $routePath,
            $repository->get('worker.lock_wait_seconds'),
            $repository->get('worker.lock_lease_seconds'),
        );
        $payload = [
            'format' => self::FORMAT,
            'providers' => $definitions,
        ];
        $this->write($artifactPath, $payload);
        $sha256 = hash_file('sha256', $artifactPath);
        if (!is_string($sha256)) {
            throw new \RuntimeException('Unable to hash generated worker topology.');
        }

        return [
            'path' => $artifactPath,
            'sha256' => $sha256,
            'providers' => count($definitions),
        ];
    }

    /**
     * @return array<string,array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float}>
     */
    public function load(string $artifactPath, string $expectedSha256): array
    {
        if (!is_file($artifactPath) || !is_readable($artifactPath)) {
            throw new \RuntimeException(sprintf('Foundation worker topology is not readable: "%s".', $artifactPath));
        }
        $actualSha256 = hash_file('sha256', $artifactPath);
        if (!is_string($actualSha256) || !hash_equals($expectedSha256, $actualSha256)) {
            throw new \RuntimeException('Foundation worker topology trust identity mismatch.');
        }

        $payload = require $artifactPath;
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT) {
            throw new \UnexpectedValueException('Foundation worker topology format is invalid.');
        }
        $providers = $payload['providers'] ?? null;
        if (!is_array($providers)) {
            throw new \UnexpectedValueException('Foundation worker topology provider map is invalid.');
        }

        return $this->normalize($providers, 0.0, 300.0);
    }

    /**
     * @return array<string,array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float}>
     */
    public function loadGeneration(LoadedReleaseGeneration $release): array
    {
        $directory = $release->releaseRoot
            . DIRECTORY_SEPARATOR . 'generations'
            . DIRECTORY_SEPARATOR . $release->generation;
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'foundation.php';

        if ($release->trustedFoundationManifestSha256 !== null) {
            $actualManifestSha256 = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
            if (!is_string($actualManifestSha256)
                || !hash_equals($release->trustedFoundationManifestSha256, $actualManifestSha256)
            ) {
                throw new \RuntimeException('Foundation generation manifest trust identity mismatch.');
            }
        }

        $manifest = FoundationReleaseManifest::load($manifestPath);
        if (($manifest['generation'] ?? null) !== $release->generation) {
            throw new \RuntimeException('Loaded Foundation worker topology belongs to a different release generation.');
        }
        $worker = FoundationReleaseManifest::section($manifest, 'worker');
        $relative = FoundationReleaseManifest::relativePath(
            $worker['provider_topology'] ?? null,
            'worker.provider_topology',
        );
        $sha256 = FoundationReleaseManifest::digest(
            $worker['provider_topology_sha256'] ?? null,
            64,
            'worker.provider_topology_sha256',
        );

        return $this->load(
            $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
            $sha256,
        );
    }

    /**
     * @return array<string,array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float}>
     */
    public function source(string $routePath, mixed $defaultWait = null, mixed $defaultLease = null): array
    {
        if (!is_file($routePath)) {
            return [];
        }

        $configured = require $routePath;
        if (!is_array($configured)) {
            throw new \UnexpectedValueException(sprintf(
                'Worker route file "%s" must return a worker map.',
                $routePath,
            ));
        }

        return $this->normalize(
            $configured,
            $this->nonNegativeFloat($defaultWait, 0.0, 'lock_wait_seconds'),
            $this->positiveFloat($defaultLease, 300.0, 'lock_lease_seconds'),
        );
    }

    private function floatValue(mixed $value, float $default, string $key): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new \UnexpectedValueException(sprintf('Worker %s must be numeric.', $key));
    }

    private function nonNegativeFloat(mixed $value, float $default, string $key): float
    {
        $resolved = $this->floatValue($value, $default, $key);
        if (!is_finite($resolved) || $resolved < 0.0) {
            throw new \UnexpectedValueException(sprintf('Worker %s must be finite and non-negative.', $key));
        }

        return $resolved;
    }

    /**
     * @param array<array-key,mixed> $configured
     * @return array<string,array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float}>
     */
    private function normalize(array $configured, float $defaultWait, float $defaultLease): array
    {
        $workers = [];
        foreach ($configured as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Worker route names must be non-empty strings.');
            }

            $options = is_array($definition) ? $definition : ['provider' => $definition];
            $provider = $options['provider'] ?? null;
            if (!is_string($provider) || $provider === '' || !is_a($provider, WorkerProvider::class, true)) {
                throw new \UnexpectedValueException(sprintf(
                    'Worker "%s" must define a %s provider.',
                    $name,
                    WorkerProvider::class,
                ));
            }

            /** @var class-string<WorkerProvider> $provider */
            $workers[$name] = [
                'provider' => $provider,
                'singleton' => ValueNormalizer::bool($options['singleton'] ?? null, false),
                'lock_wait_seconds' => $this->nonNegativeFloat(
                    $options['lock_wait_seconds'] ?? null,
                    $defaultWait,
                    'lock_wait_seconds',
                ),
                'lock_lease_seconds' => $this->positiveFloat(
                    $options['lock_lease_seconds'] ?? null,
                    $defaultLease,
                    'lock_lease_seconds',
                ),
            ];
        }
        ksort($workers);

        return $workers;
    }

    private function path(string $basePath, string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR);
    }

    private function positiveFloat(mixed $value, float $default, string $key): float
    {
        $resolved = $this->floatValue($value, $default, $key);
        if (!is_finite($resolved) || $resolved <= 0.0) {
            throw new \UnexpectedValueException(sprintf('Worker %s must be positive and finite.', $key));
        }

        return $resolved;
    }

    /** @param array<string,mixed> $payload */
    private function write(string $artifactPath, array $payload): void
    {
        $directory = dirname($artifactPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create worker topology directory "%s".', $directory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        $temporary = tempnam($directory, '.worker-topology-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to stage Foundation worker topology.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, 0644)
                || !rename($temporary, $artifactPath)
            ) {
                throw new \RuntimeException(sprintf(
                    'Unable to publish Foundation worker topology "%s".',
                    $artifactPath,
                ));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
