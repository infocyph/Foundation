<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\OTP\TOTP;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float} */
function otp61Measure(callable $operation, int $operations, int $repetitions, int $warmup): array
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

function otp61Ratio(float $numerator, float $denominator): float
{
    return round($numerator / max(1.0, $denominator), 4);
}

$operations = max(100, (int) (getenv('OTP_RUNTIME_OPERATIONS') ?: 1_000));
$repetitions = max(3, (int) (getenv('OTP_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(20, (int) (getenv('OTP_RUNTIME_WARMUP') ?: 100));

$metadataSecret = TOTP::generateSecret(20);
$foundation = new OtpProvisioningService(
    issuer: 'Foundation',
    algorithm: 'sha1',
    digits: 6,
    period: 30,
    secretBytes: 20,
);

$subjects = [];
$subjects['direct_totp_provisioning'] = otp61Measure(
    static function (): void {
        $secret = TOTP::generateSecret(20);
        new TOTP($secret, 6, 30, 'sha1')
            ->getEnrollmentPayload('benchmark@example.test', 'Foundation');
    },
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_totp_metadata_bridge'] = otp61Measure(
    static fn() => $foundation->factorMetadata($metadataSecret, 'benchmark@example.test'),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_totp_provisioning'] = otp61Measure(
    static fn() => $foundation->provisionTotp('benchmark-account', 'benchmark@example.test'),
    $operations,
    $repetitions,
    $warmup,
);

$directNs = (float) $subjects['direct_totp_provisioning']['median_ns'];
$foundationNs = (float) $subjects['foundation_totp_provisioning']['median_ns'];

$report = [
    'benchmark' => 'foundation-otp-6.1-utilization',
    'versions' => [
        'php' => PHP_VERSION,
        'foundation' => InstalledVersions::getPrettyVersion('infocyph/foundation'),
        'otp' => InstalledVersions::getPrettyVersion('infocyph/otp'),
    ],
    'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
    'operations_per_repetition' => $operations,
    'repetitions' => $repetitions,
    'warmup_operations' => $warmup,
    'subjects' => $subjects,
    'ratios' => [
        'foundation_provisioning_vs_direct_otp' => otp61Ratio($foundationNs, $directNs),
    ],
    'note' => 'Native and Foundation provisioning both include fresh secret generation, TOTP construction, and enrollment payload creation. Replay, cache coordination, durable CAS, and WebAuthn cryptography remain specialist-layer costs.',
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
];

file_put_contents(
    'php://stdout',
    json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);
