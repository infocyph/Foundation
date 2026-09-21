<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Validation\ReqShieldDatabaseProvider;
use Infocyph\Foundation\Validation\ValidationSchemaRegistry;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\Validator;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float}
 */
function reqShield31Measure(callable $operation, int $operations, int $repetitions, int $warmup): array
{
    $samples = [];

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($iteration = 0; $iteration < $warmup; ++$iteration) {
            $operation();
        }

        $started = hrtime(true);
        for ($iteration = 0; $iteration < $operations; ++$iteration) {
            $operation();
        }
        $samples[] = max(1, hrtime(true) - $started) / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];
    $minimum = $samples[0];
    $maximum = $samples[count($samples) - 1];

    return [
        'median_ns' => round($median, 2),
        'min_ns' => round($minimum, 2),
        'max_ns' => round($maximum, 2),
        'spread_percent' => round((($maximum - $minimum) / max(1.0, $median)) * 100, 2),
    ];
}

function reqShield31Ratio(float $numerator, float $denominator): float
{
    return round($numerator / max(1.0, $denominator), 4);
}

$operations = max(100, (int) (getenv('REQSHIELD_RUNTIME_OPERATIONS') ?: 1_000));
$dbOperations = max(20, (int) (getenv('REQSHIELD_DATABASE_OPERATIONS') ?: 100));
$repetitions = max(3, (int) (getenv('REQSHIELD_RUNTIME_REPETITIONS') ?: 5));
$warmup = max(10, (int) (getenv('REQSHIELD_RUNTIME_WARMUP') ?: 50));

$flatRules = [
    'email' => [
        'rules' => 'required|email|max:255',
        'sanitize' => ['trim', 'lowercase'],
    ],
    'age' => [
        'rules' => 'required|integer|min:18|max:120',
        'cast' => 'integer',
    ],
    'profile.name' => 'required|string|min:2|max:80',
];
$flatPayload = [
    'email' => ' BENCH@EXAMPLE.TEST ',
    'age' => '31',
    'profile' => ['name' => 'Benchmark'],
];

$flatConfig = new ConfigRepository([
    'validation' => [
        'defaults' => [
            'nested' => true,
            'nested_mode' => 'required',
            'strip_unknown' => true,
        ],
        'schemas' => ['benchmark.flat' => $flatRules],
    ],
]);
$flatRegistry = new ValidationSchemaRegistry($flatConfig);
$flatFactory = new ValidatorFactory($flatConfig, $flatRegistry);
$directFlat = Validator::make($flatRules)
    ->enableNestedValidation(false)
    ->stripUnknown();
$foundationFlat = $flatFactory->make('benchmark.flat');

$databaseRoot = sys_get_temp_dir() . '/foundation-reqshield-benchmark-' . bin2hex(random_bytes(5));
mkdir($databaseRoot, 0700, true);
$database = $databaseRoot . '/validation.sqlite';

DB::resetRuntimeState();
$connection = DB::addConnection([
    'driver' => 'sqlite',
    'database' => $database,
    'security' => ['max_params' => 64],
], 'reqshield-benchmark');
$connection->statement('CREATE TABLE teams (id INTEGER PRIMARY KEY)');
$connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, deleted_at TEXT NULL)');
$connection->insert('INSERT INTO teams (id) VALUES (?)', [1]);
$connection->insert(
    'INSERT INTO users (id, email, deleted_at) VALUES (?, ?, ?)',
    [1, 'existing@example.test', null],
);

$provider = new ReqShieldDatabaseProvider(static fn() => $connection);
$dbRules = [
    'team_id' => 'required|integer|exists:teams,id',
    'email' => 'required|email|unique:users,email',
];
$dbPayload = ['team_id' => 1, 'email' => 'fresh@example.test'];
$dbConfig = new ConfigRepository([
    'validation' => ['schemas' => ['benchmark.db' => $dbRules]],
]);
$dbRegistry = new ValidationSchemaRegistry($dbConfig);
$dbFactory = new ValidatorFactory($dbConfig, $dbRegistry, $provider);
$directDb = Validator::make($dbRules, $provider);
$foundationDb = $dbFactory->make('benchmark.db');

$assertPass = static function (mixed $result, string $subject): void {
    if (!is_object($result) || !method_exists($result, 'passes') || $result->passes() !== true) {
        throw new RuntimeException($subject . ' benchmark validation failed.');
    }
};

try {
    $subjects = [
        'direct_reqshield_reuse' => reqShield31Measure(
            static function () use ($directFlat, $flatPayload, $assertPass): void {
                $assertPass($directFlat->validate($flatPayload), 'Direct ReqShield reuse');
            },
            $operations,
            $repetitions,
            $warmup,
        ),
        'foundation_validator_reuse' => reqShield31Measure(
            static function () use ($foundationFlat, $flatPayload, $assertPass): void {
                $assertPass($foundationFlat->validate($flatPayload), 'Foundation validator reuse');
            },
            $operations,
            $repetitions,
            $warmup,
        ),
        'direct_reqshield_construct_validate' => reqShield31Measure(
            static function () use ($flatRules, $flatPayload, $assertPass): void {
                $validator = Validator::make($flatRules)
                    ->enableNestedValidation(false)
                    ->stripUnknown();
                $assertPass($validator->validate($flatPayload), 'Direct ReqShield construct');
            },
            $operations,
            $repetitions,
            $warmup,
        ),
        'foundation_factory_construct_validate' => reqShield31Measure(
            static function () use ($flatFactory, $flatPayload, $assertPass): void {
                $assertPass(
                    $flatFactory->make('benchmark.flat')->validate($flatPayload),
                    'Foundation factory construct',
                );
            },
            $operations,
            $repetitions,
            $warmup,
        ),
        'direct_reqshield_database' => reqShield31Measure(
            static function () use ($directDb, $dbPayload, $assertPass): void {
                $assertPass($directDb->validate($dbPayload), 'Direct ReqShield DB');
            },
            $dbOperations,
            $repetitions,
            max(5, intdiv($warmup, 5)),
        ),
        'foundation_database_bridge' => reqShield31Measure(
            static function () use ($foundationDb, $dbPayload, $assertPass): void {
                $assertPass($foundationDb->validate($dbPayload), 'Foundation DB bridge');
            },
            $dbOperations,
            $repetitions,
            max(5, intdiv($warmup, 5)),
        ),
    ];

    $result = [
        'schema_version' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'metadata' => [
            'suite' => 'foundation-reqshield-3.1-utilization',
            'reqshield' => InstalledVersions::getPrettyVersion('infocyph/reqshield') ?? 'unknown',
            'dblayer' => InstalledVersions::getPrettyVersion('infocyph/dblayer') ?? 'unknown',
            'boundary' => 'ReqShield mechanics and bounded plan cache with Foundation immutable schema/config/DBLayer adaptation',
        ],
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'operations_per_repetition' => $operations,
        'database_operations_per_repetition' => $dbOperations,
        'repetitions' => $repetitions,
        'subjects' => $subjects,
        'ratios' => [
            'foundation_reuse_vs_direct_reqshield' => reqShield31Ratio(
                $subjects['foundation_validator_reuse']['median_ns'],
                $subjects['direct_reqshield_reuse']['median_ns'],
            ),
            'foundation_factory_vs_direct_construct' => reqShield31Ratio(
                $subjects['foundation_factory_construct_validate']['median_ns'],
                $subjects['direct_reqshield_construct_validate']['median_ns'],
            ),
            'foundation_database_vs_direct_reqshield' => reqShield31Ratio(
                $subjects['foundation_database_bridge']['median_ns'],
                $subjects['direct_reqshield_database']['median_ns'],
            ),
        ],
        'attribution' => [
            'plan_cache' => 'ReqShield owns the bounded process ValidationPlan cache; Foundation does not add a second plan cache.',
            'factory' => 'Foundation overhead is immutable named-schema lookup plus application validation-profile configuration.',
            'database' => 'Both database subjects use the same Foundation DBLayer provider so the ratio isolates schema/factory policy rather than physical DB I/O.',
        ],
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
    ];

    $build = dirname(__DIR__) . '/build';
    if (!is_dir($build) && !mkdir($build, 0777, true) && !is_dir($build)) {
        throw new RuntimeException('Unable to create ReqShield benchmark output directory.');
    }

    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($build . '/reqshield-3.1-benchmark.json', $encoded . PHP_EOL);
    fwrite(STDOUT, $encoded . PHP_EOL);
} finally {
    DB::resetRuntimeState();
    if (is_file($database)) {
        unlink($database);
    }
    is_dir($databaseRoot) && rmdir($databaseRoot);
}
