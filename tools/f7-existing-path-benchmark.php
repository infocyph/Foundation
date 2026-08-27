<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Authentication\TokenAuth\ApplicationTokenService;
use Infocyph\Foundation\Auth\Contract\ApplicationTokenStoreInterface;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Webrick\Request\Request;

require __DIR__ . '/../vendor/autoload.php';

$output = $argv[1] ?? 'build/f7-existing-path.json';
$iterations = 20_000;
$warmup = 2_000;
$runs = 7;
$root = dirname(__DIR__);
$routesPath = $root . '/routes';
$routeFile = $routesPath . '/web.php';
$routeDirectoryExists = is_dir($routesPath);
$routeFileExists = is_file($routeFile);
$routeFileContents = $routeFileExists ? file_get_contents($routeFile) : null;
if ($routeFileExists && !is_string($routeFileContents)) {
    throw new RuntimeException('Unable to snapshot benchmark route file.');
}
if (!$routeDirectoryExists && !mkdir($routesPath, 0775, true) && !is_dir($routesPath)) {
    throw new RuntimeException('Unable to create benchmark routes directory.');
}
if (!$routeFileExists && file_put_contents($routeFile, "<?php\n") === false) {
    throw new RuntimeException('Unable to create benchmark route file.');
}

$store = new class implements ApplicationTokenStoreInterface {
    public function isRevoked(string $jti, int $now): bool
    {
        return false;
    }

    public function revoke(string $jti, int $expiresAt): void {}
};

try {
    $app = Foundation::web([
        'base_path' => $root,
        'app' => ['env' => 'testing'],
        'auth' => [
            'oauth' => ['enabled' => false],
        ],
    ])->boot();
    $tokens = $app->make(ApplicationTokenService::class);
    $token = $tokens->issue(new Principal('benchmark-account', PrincipalType::ACCOUNT, 'benchmark-account'));
    $resolver = new BearerTokenPrincipalResolver($tokens, $store);
    $request = Request::fake(method: 'GET', uri: '/benchmark')
        ->withHeader('Authorization', 'Bearer ' . $token);

    for ($i = 0; $i < $warmup; $i++) {
        $principal = $resolver->resolve($request);
        if (!$principal instanceof Principal || $principal->accountId() !== 'benchmark-account') {
            throw new RuntimeException('Representative bearer benchmark warmup failed.');
        }
    }

    $rpms = [];
    for ($run = 0; $run < $runs; $run++) {
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $principal = $resolver->resolve($request);
            if (!$principal instanceof Principal || $principal->accountId() !== 'benchmark-account') {
                throw new RuntimeException('Representative bearer benchmark failed.');
            }
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        if ($elapsed <= 0.0) {
            throw new RuntimeException('Representative bearer benchmark elapsed time is invalid.');
        }
        $rpms[] = ($iterations / $elapsed) * 60.0;
    }

    sort($rpms, SORT_NUMERIC);
    $median = $rpms[intdiv(count($rpms), 2)];
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    $document = [
        'schema_version' => 1,
        'workload' => 'application_bearer_resolve',
        'iterations_per_run' => $iterations,
        'runs' => $runs,
        'rpm' => $rpms,
        'median_rpm' => $median,
        'environment' => [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'kernel' => php_uname('r'),
            'machine' => php_uname('m'),
            'processor' => trim((string) shell_exec("grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2-")),
            'extensions' => $extensions,
            'fingerprint' => getenv('FOUNDATION_BENCHMARK_FINGERPRINT') ?: null,
            'ref' => getenv('FOUNDATION_BENCHMARK_REF') ?: null,
        ],
    ];

    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
    }
    if (file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
        throw new RuntimeException(sprintf('Unable to write benchmark output "%s".', $output));
    }
    fwrite(STDOUT, json_encode($document, JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    if (is_file($routeFile) && !unlink($routeFile)) {
        throw new RuntimeException(sprintf('Unable to remove benchmark route file "%s".', $routeFile));
    }
    if (is_dir($routesPath) && !rmdir($routesPath)) {
        throw new RuntimeException(sprintf('Unable to remove benchmark routes directory "%s".', $routesPath));
    }
    if ($routeFileExists && is_string($routeFileContents)) {
        if (!is_dir($routesPath) && !mkdir($routesPath, 0775, true) && !is_dir($routesPath)) {
            throw new RuntimeException('Unable to restore benchmark routes directory.');
        }
        if (file_put_contents($routeFile, $routeFileContents) === false) {
            throw new RuntimeException('Unable to restore benchmark route file.');
        }
    } elseif ($routeDirectoryExists && !is_dir($routesPath) && !mkdir($routesPath, 0775, true) && !is_dir($routesPath)) {
        throw new RuntimeException('Unable to restore benchmark routes directory.');
    }
}
