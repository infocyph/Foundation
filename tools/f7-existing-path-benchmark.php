<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;

require getcwd() . '/vendor/autoload.php';

$output = $argv[1] ?? 'build/f7-existing-path.json';
$repetitions = 7;
$warmupOperations = 250;
$operations = 2_500;
$basePath = sys_get_temp_dir() . '/foundation-f7-existing-' . bin2hex(random_bytes(6));
$routesPath = $basePath . '/routes';
if (!mkdir($routesPath, 0775, true) && !is_dir($routesPath)) {
    throw new RuntimeException(sprintf('Unable to create benchmark directory "%s".', $routesPath));
}
$routeFile = $routesPath . '/web.php';
$routeSource = <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/application-bearer', static fn(): Response => Response::json(['authenticated' => true]), [
    'middleware' => ['resolve-auth', 'auth'],
]);
PHP;
if (file_put_contents($routeFile, $routeSource) === false) {
    throw new RuntimeException(sprintf('Unable to write benchmark route file "%s".', $routeFile));
}

try {
    $application = Foundation::web([
        'app' => ['base_path' => $basePath],
        '_config_cache' => false,
        'auth' => [
            'http' => ['principal_resolvers' => ['bearer']],
        ],
        'router' => [
            'cache' => false,
            'files' => ['web.php'],
            'middleware' => [
                'globals' => [
                    'pre' => [],
                    'post' => [],
                ],
            ],
        ],
        'session' => ['driver' => 'array'],
    ])->boot();

    $services = new AuthServices($application);
    $created = $services->accounts()->create(
        'f7-existing-path@example.test',
        $services->passwordHasher()->hash('f7-benchmark-secret'),
    );
    $account = $created->account;
    if (!$account instanceof Account) {
        throw new LogicException('F7 benchmark account was not created.');
    }

    $now = time();
    $token = $services->tokens()->issueAccessToken(new AccessTokenClaims(
        subjectId: $account->id(),
        actorId: null,
        issuedAt: $now,
        expiresAt: $now + 3_600,
    ))->token;
    if (!is_string($token) || $token === '') {
        throw new LogicException('F7 benchmark access token was not issued.');
    }

    $request = Request::fake(
        headers: [
            'Authorization' => 'Bearer ' . $token,
            'Host' => 'benchmark.test',
        ],
        uri: 'https://benchmark.test/application-bearer',
    );
    $operation = static function () use ($application, $request): void {
        $response = $application->handle($request);
        if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== '{"authenticated":true}') {
            throw new RuntimeException('F7 existing-path benchmark request failed validation.');
        }
    };

    $samples = [];
    for ($sample = 0; $sample < $repetitions; $sample++) {
        for ($warmup = 0; $warmup < $warmupOperations; $warmup++) {
            $operation();
        }

        $started = hrtime(true);
        for ($iteration = 0; $iteration < $operations; $iteration++) {
            $operation();
        }
        $duration = max(0.000001, (hrtime(true) - $started) / 1_000_000_000);
        $samples[] = ($operations / $duration) * 60;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];
    $cpu = php_uname('m');
    $cpuInfo = is_file('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : false;
    if (is_string($cpuInfo) && preg_match('/^model name\s*:\s*(.+)$/mi', $cpuInfo, $matches) === 1) {
        $cpu = trim($matches[1]);
    }
    $extensions = get_loaded_extensions();
    sort($extensions);
    $document = [
        'schema_version' => 1,
        'workload' => 'oauth-disabled-existing-application-bearer',
        'repetitions' => $repetitions,
        'warmup_operations_per_sample' => $warmupOperations,
        'operations_per_sample' => $operations,
        'sample_rpm' => $samples,
        'median_rpm' => $median,
        'environment' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'operating_system' => php_uname('s') . ' ' . php_uname('r'),
            'cpu_model' => $cpu,
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
    echo json_encode($document, JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if (is_file($routeFile) && !unlink($routeFile)) {
        throw new RuntimeException(sprintf('Unable to remove benchmark route file "%s".', $routeFile));
    }
    if (is_dir($routesPath) && !rmdir($routesPath)) {
        throw new RuntimeException(sprintf('Unable to remove benchmark routes directory "%s".', $routesPath));
    }
    if (is_dir($basePath) && !rmdir($basePath)) {
        throw new RuntimeException(sprintf('Unable to remove benchmark directory "%s".', $basePath));
    }
}
