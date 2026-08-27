<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Support\HmacTokenCodec;
use Infocyph\Foundation\Auth\Support\InMemoryAccountStore;
use Infocyph\Foundation\Auth\Support\SimpleAccessTokenService;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Webrick\Request\Request;

require __DIR__ . '/../vendor/autoload.php';

$output = $argv[1] ?? 'build/f7-existing-path.json';
$iterations = 20_000;
$warmup = 2_000;
$runs = 7;
$clock = new SystemClock();
$accounts = new InMemoryAccountStore();
$accounts->save(new Account('benchmark-account', 'benchmark-account'));
$tokens = new SimpleAccessTokenService(
    new HmacTokenCodec(str_repeat('b', 32)),
    $clock,
);
$now = $clock->now();
$token = $tokens->issue(new AccessTokenClaims(
    subjectId: 'benchmark-account',
    actorId: null,
    issuedAt: $now,
    expiresAt: $now + 3600,
));
$resolver = new BearerTokenPrincipalResolver(
    new ConfigRepository([
        'auth' => [
            'http' => [
                'bearer_header' => 'Authorization',
                'bearer_prefix' => 'Bearer ',
            ],
        ],
    ]),
    $tokens,
    $accounts,
);
$request = Request::fake(method: 'GET', uri: '/benchmark')
    ->withHeader('Authorization', 'Bearer ' . $token);

for ($i = 0; $i < $warmup; $i++) {
    $principal = $resolver->resolve($request);
    if ($principal?->accountId() !== 'benchmark-account') {
        throw new RuntimeException('Representative bearer benchmark warmup failed.');
    }
}

$rpms = [];
for ($run = 0; $run < $runs; $run++) {
    $started = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $principal = $resolver->resolve($request);
        if ($principal?->accountId() !== 'benchmark-account') {
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
