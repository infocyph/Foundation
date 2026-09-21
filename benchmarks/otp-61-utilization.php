<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DateTimeImmutable;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Support\InMemoryMfaFactorStore;
use Infocyph\OTP\Stores\InMemoryRecoveryCodeStore;
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

$protectionKey = (new KeyMaterialGenerator())->forAead();
$protectionRing = new KeyRing([
    new KeyRingEntry(
        id: 'benchmark-active',
        key: $protectionKey,
        status: KeyStatus::ACTIVE,
        purpose: KeyPurpose::DATA_PROTECTION,
        algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
    ),
]);
$mfaFactor = new MfaFactor(
    id: 'benchmark-factor',
    accountId: 'benchmark-account',
    type: 'totp',
    label: 'Benchmark',
    enabled: true,
    createdAt: 1_700_000_000,
    metadata: [
        'otp' => [
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'secret' => $metadataSecret,
        ],
    ],
);
$directProtector = new StringProtector();
$foundationProtector = new MfaSecretProtector($protectionRing);
$protectionOptions = new ProtectionOptions(
    MfaSecretProtector::PURPOSE,
    implode("\0", [
        'foundation:mfa-factor:v1',
        $mfaFactor->accountId,
        $mfaFactor->id,
        $mfaFactor->type,
        'secret',
    ]),
);
$recoveryIssuedAt = new DateTimeImmutable('@1700000000');
$recoveryDigests = [
    hash('sha256', 'benchmark-recovery-one'),
    hash('sha256', 'benchmark-recovery-two'),
    hash('sha256', 'benchmark-recovery-three'),
];
$directRecovery = new InMemoryRecoveryCodeStore();
$foundationRecovery = new OtpRecoveryCodeStore(new InMemoryMfaFactorStore());

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
$subjects['direct_epicrypt_mfa_string_protection'] = otp61Measure(
    static fn() => $directProtector->protectWithKeyRing(
        $metadataSecret,
        $protectionRing,
        $protectionOptions,
    ),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_mfa_secret_protection_bridge'] = otp61Measure(
    static fn() => $foundationProtector->protect($mfaFactor),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['direct_otp_recovery_replace'] = otp61Measure(
    static fn() => $directRecovery->replace(
        'account:benchmark-account',
        $recoveryDigests,
        $recoveryIssuedAt,
    ),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_recovery_cas_bridge'] = otp61Measure(
    static fn() => $foundationRecovery->replace(
        'account:benchmark-account',
        $recoveryDigests,
        $recoveryIssuedAt,
    ),
    $operations,
    $repetitions,
    $warmup,
);

$directNs = (float) $subjects['direct_totp_provisioning']['median_ns'];
$foundationNs = (float) $subjects['foundation_totp_provisioning']['median_ns'];
$directProtectionNs = (float) $subjects['direct_epicrypt_mfa_string_protection']['median_ns'];
$foundationProtectionNs = (float) $subjects['foundation_mfa_secret_protection_bridge']['median_ns'];
$directRecoveryNs = (float) $subjects['direct_otp_recovery_replace']['median_ns'];
$foundationRecoveryNs = (float) $subjects['foundation_recovery_cas_bridge']['median_ns'];

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
        'foundation_mfa_protection_vs_direct_epicrypt' => otp61Ratio(
            $foundationProtectionNs,
            $directProtectionNs,
        ),
        'foundation_recovery_state_vs_direct_otp_store' => otp61Ratio(
            $foundationRecoveryNs,
            $directRecoveryNs,
        ),
    ],
    'note' => 'Provisioning compares direct OTP with the Foundation mapping layer. MFA-secret protection isolates Foundation factor/AAD mapping over Epicrypt data protection. Recovery replacement isolates Foundation revision/CAS state mapping over the OTP recovery-store contract. External DB/cache I/O and WebAuthn cryptography are measured separately from these in-process attribution pairs.',
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
];

file_put_contents(
    'php://stdout',
    json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);
