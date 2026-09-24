<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdClaimsProviderInterface;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdSubjectIdentifierProviderInterface;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdUserInfoProjector;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenManager;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenPolicy;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenRecord;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenStoreInterface;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenWildcardPolicy;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\DataProtection\FileProtector;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEpicryptPersonalAccessTokenStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordVerifier;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthJwkSetProvider;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdClaimsProvider;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdSubjectProvider;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthPersonalAccessTokenSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\SignedUrlKeyResolver;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Infocyph\Foundation\Security\EnvironmentFileProtector;
use Infocyph\Foundation\Testing\FrozenClock;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
/**
 * @param array<string, array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float}> $subjects
 * @return array<string, float>
 */
function epicrypt31Ratios(array $subjects): array
{
    $pairs = [
        'foundation_signed_url_vs_direct_webrick' => ['foundation_signed_url_policy', 'direct_webrick_signed_url'],
        'foundation_mfa_protection_vs_direct_epicrypt' => ['foundation_mfa_protection', 'direct_epicrypt_string_protection'],
        'foundation_purpose_token_vs_direct_epicrypt' => ['foundation_purpose_token', 'direct_epicrypt_purpose_token'],
        'foundation_jwks_vs_direct_epicrypt' => ['foundation_jwks_publication', 'direct_epicrypt_jwks'],
        'foundation_env_file_vs_direct_epicrypt' => ['foundation_environment_file_protection', 'direct_epicrypt_file_protection'],
        'foundation_password_vs_direct_epicrypt' => ['foundation_password_verification', 'direct_epicrypt_password_verification'],
        'foundation_oauth_resource_vs_direct_epicrypt' => ['foundation_oauth_resource_validation', 'direct_epicrypt_oauth_resource_validation'],
        'foundation_oidc_userinfo_vs_direct_epicrypt' => ['foundation_oidc_userinfo', 'direct_epicrypt_oidc_userinfo'],
        'foundation_pat_db_vs_direct_epicrypt' => ['foundation_pat_db_verification', 'direct_epicrypt_pat_verification'],
    ];

    $ratios = [];
    foreach ($pairs as $name => [$foundation, $direct]) {
        $ratios[$name] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::ratio(
            (float) $subjects[$foundation]['median_ns'],
            (float) $subjects[$direct]['median_ns'],
        );
    }

    return $ratios;
}

$operations = max(25, (int) (getenv('EPICRYPT_RUNTIME_OPERATIONS') ?: 100));
$repetitions = max(3, (int) (getenv('EPICRYPT_RUNTIME_REPETITIONS') ?: 5));
$warmup = max(5, (int) (getenv('EPICRYPT_RUNTIME_WARMUP') ?: 20));
$fileOperations = max(5, intdiv($operations, 10));
$fileWarmup = max(1, intdiv($warmup, 5));

$clock = new FrozenClock(1_700_000_000);
$psrClock = new EpicryptClockAdapter($clock);
$keyGenerator = new KeyMaterialGenerator();
$master = str_repeat('m', 64);
$deriver = new KeyDeriver();

$signedEnvironment = 'FOUNDATION_BENCH_SIGNED_URL_ROOT';
$_ENV[$signedEnvironment] = $master;
$_SERVER[$signedEnvironment] = $master;
putenv($signedEnvironment . '=' . $master);
$signedKey = $deriver->derivePurposeKeyBinary($master, SignedUrlKeyResolver::DOMAIN, length: 32);
$signedConfig = new SignedUrlConfig(
    generationKey: $signedKey,
    verificationKeys: [$signedKey],
);
$directUrlGenerator = new UrlGenerator(
    'https://benchmark.example.test',
    ['download' => ['/download/{id}', null]],
    signedConfig: $signedConfig,
);
$signedResolver = new SignedUrlKeyResolver(new ConfigRepository([
    'router' => [
        'signed_urls' => [
            'keys' => [[
                'id' => 'benchmark',
                'environment' => $signedEnvironment,
                'status' => 'active',
            ]],
        ],
    ],
]));
$foundationUrlGenerator = new UrlGenerator(
    'https://benchmark.example.test',
    ['download' => ['/download/{id}', null]],
    signedConfig: $signedResolver->resolve(),
);

$mfaKey = $keyGenerator->forAead();
$mfaRing = new KeyRing([
    new KeyRingEntry(
        id: 'benchmark',
        key: $mfaKey,
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
    createdAt: $clock->now(),
    metadata: ['otp' => ['secret' => 'JBSWY3DPEHPK3PXP']],
);
$mfaOptions = new ProtectionOptions(
    MfaSecretProtector::PURPOSE,
    implode("\0", [
        'foundation:mfa-factor:v1',
        $mfaFactor->accountId,
        $mfaFactor->id,
        $mfaFactor->type,
        'secret',
    ]),
);
$directStringProtector = new StringProtector($psrClock);
$foundationMfaProtector = new MfaSecretProtector($mfaRing, protector: $directStringProtector);

$tokenEnvironment = 'FOUNDATION_BENCH_TOKEN_ROOT';
$_ENV[$tokenEnvironment] = $master;
$_SERVER[$tokenEnvironment] = $master;
putenv($tokenEnvironment . '=' . $master);
$tokenDomain = 'foundation.auth.simple-token.benchmark.v1';
$tokenContext = 'foundation.auth.simple-token.v1';
$purposeKey = $deriver->derivePurposeKeyBinary($master, $tokenDomain, $tokenContext, 64);
$directPurposeToken = new PurposeToken(
    keys: $purposeKey,
    purpose: $tokenDomain,
    context: $tokenContext,
    ttlSeconds: 300,
    clock: $psrClock,
);
$foundationPurposeToken = new EpicryptPurposeTokenFactory(
    new AuthSecretResolver(new ConfigRepository([
        'auth' => ['token_secret_environment' => $tokenEnvironment],
    ])),
    $clock,
);

$password = 'correct horse battery staple';
$passwordHasher = new PasswordHasher();
$passwordHash = $passwordHasher->hashPassword($password);
$foundationPasswordVerifier = new EpicryptPasswordVerifier($passwordHasher);

$signingPair = KeyPairGenerator::ec()->generate();
$signingAlgorithm = AsymmetricJwtAlgorithm::ES256;
$signingKeyId = 'epicrypt-benchmark';
$signingKeys = new AsymmetricSigningKeySet(
    issuer: 'https://benchmark-issuer.example.test',
    activeKeyId: $signingKeyId,
    privateKey: $signingPair['private'],
    publicKeys: new KeyRing([
        new KeyRingEntry(
            id: $signingKeyId,
            key: $signingPair['public'],
            status: KeyStatus::ACTIVE,
            purpose: KeyPurpose::API_PERSONAL_TOKEN_SIGNING,
            algorithm: $signingAlgorithm->value,
            issuer: 'https://benchmark-issuer.example.test',
        ),
    ]),
    algorithm: $signingAlgorithm,
    purpose: KeyPurpose::API_PERSONAL_TOKEN_SIGNING,
);

$oauthFixture = new OAuth21FlowFixture();
$oauthAudience = 'https://benchmark-resource.example.test';
$oauthRegistration = $oauthFixture->clients->register(
    OAuthClientType::Confidential,
    [OAuthGrantType::ClientCredentials],
    [],
    ['benchmark.read'],
    [$oauthAudience],
);
$oauthSecret = $oauthRegistration->secret;
if (!is_string($oauthSecret) || $oauthSecret === '') {
    throw new LogicException('Epicrypt benchmark OAuth client secret was not issued.');
}
$oauthToken = $oauthFixture->tokens->exchange([
    'grant_type' => OAuthGrantType::ClientCredentials->value,
    'scope' => 'benchmark.read',
], new OAuthClientAuthentication(
    OAuthClientAuthenticationMethod::ClientSecretBasic,
    $oauthRegistration->client->clientId,
    $oauthSecret,
))->accessToken;
$foundationJwks = new EpicryptOAuthJwkSetProvider($oauthFixture->keys);

$directOidc = new OpenIdUserInfoProjector(
    new class implements OpenIdSubjectIdentifierProviderInterface {
        public function subject(string $principalId, string $clientId): string
        {
            unset($clientId);

            return $principalId;
        }
    },
    new class implements OpenIdClaimsProviderInterface {
        public function claims(string $principalId, string $clientId, array $scopes): array
        {
            unset($principalId, $clientId);

            return in_array('email', $scopes, true)
                ? ['email' => 'account@example.test']
                : [];
        }
    },
);
$foundationOidc = new OpenIdUserInfoProjector(
    new FoundationOpenIdSubjectProvider(),
    new FoundationOpenIdClaimsProvider($oauthFixture->accounts),
);

$directPatStore = new class implements PersonalAccessTokenStoreInterface {
    /** @var array<string, PersonalAccessTokenRecord> */
    private array $records = [];

    public function create(PersonalAccessTokenRecord $record): bool
    {
        if (isset($this->records[$record->tokenId])) {
            return false;
        }

        $this->records[$record->tokenId] = $record;

        return true;
    }

    public function find(string $tokenId): ?PersonalAccessTokenRecord
    {
        return $this->records[$tokenId] ?? null;
    }

    public function listForSubject(string $subject, int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->records,
            static fn(PersonalAccessTokenRecord $record): bool => $record->subject === $subject,
        )), 0, $limit);
    }

    public function revoke(string $tokenId, string $subject, int $revokedAt): ?PersonalAccessTokenRecord
    {
        $record = $this->records[$tokenId] ?? null;
        if (!$record instanceof PersonalAccessTokenRecord || !hash_equals($record->subject, $subject)) {
            return null;
        }

        return $this->records[$tokenId] = new PersonalAccessTokenRecord(
            $record->tokenId,
            $record->subject,
            $record->name,
            $record->abilities,
            $record->createdAt,
            $record->expiresAt,
            $record->revokedAt ?? $revokedAt,
        );
    }

    public function revokeAll(string $subject, int $revokedAt): int
    {
        $count = 0;
        foreach ($this->records as $tokenId => $record) {
            if (!hash_equals($record->subject, $subject) || $record->revokedAt !== null) {
                continue;
            }
            $this->records[$tokenId] = new PersonalAccessTokenRecord(
                $record->tokenId,
                $record->subject,
                $record->name,
                $record->abilities,
                $record->createdAt,
                $record->expiresAt,
                $revokedAt,
            );
            ++$count;
        }

        return $count;
    }
};
$patPolicy = new PersonalAccessTokenPolicy(
    audience: 'https://benchmark-api.example.test',
    defaultLifetimeSeconds: 3600,
    maximumLifetimeSeconds: 3600,
    wildcardPolicy: PersonalAccessTokenWildcardPolicy::DISABLED,
);
$directPat = new PersonalAccessTokenManager(
    $signingKeys,
    $directPatStore,
    $patPolicy,
    clock: $psrClock,
);
$directPatToken = $directPat->issue('benchmark-account', 'benchmark', ['benchmark.read'])->token;

DB::purge();
$patRoot = sys_get_temp_dir() . '/foundation-epicrypt-benchmark-' . bin2hex(random_bytes(5));
mkdir($patRoot, 0700, true);
$patDatabase = $patRoot . '/pat.sqlite';
$runtimeState = new RuntimeExecutionState();
$container = new readonly class ($runtimeState) implements ContainerInterface {
    public function __construct(private RuntimeExecutionState $state) {}

    public function get(string $id): mixed
    {
        if ($id === RuntimeExecutionState::class) {
            return $this->state;
        }

        throw new LogicException(sprintf('Epicrypt benchmark container has no service "%s".', $id));
    }

    public function has(string $id): bool
    {
        return $id === RuntimeExecutionState::class;
    }
};
$patFactory = new DBLayerFactory(
    new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'epicrypt-benchmark',
            'connections' => [
                'epicrypt-benchmark' => [
                    'driver' => 'sqlite',
                    'database' => $patDatabase,
                ],
            ],
        ],
    ])),
    $container,
);
$patTables = new AuthTables();
new MigrationRunner(
    $patFactory->connection(),
    [new AuthPersonalAccessTokenSchema($patTables)],
)->run();
$foundationPat = new PersonalAccessTokenManager(
    $signingKeys,
    new DBLayerEpicryptPersonalAccessTokenStore($patFactory, $patTables),
    $patPolicy,
    clock: $psrClock,
);
$foundationPatToken = $foundationPat->issue(
    'benchmark-account',
    'benchmark',
    ['benchmark.read'],
)->token;

$fileRoot = sys_get_temp_dir() . '/foundation-epicrypt-file-benchmark-' . bin2hex(random_bytes(5));
mkdir($fileRoot, 0700, true);
file_put_contents($fileRoot . '/source.env', "APP_ENV=benchmark\nSECRET=benchmark-value\n");
$fileKeyEnvironment = 'FOUNDATION_BENCH_ENV_FILE_KEY';
$fileKey = $keyGenerator->forSecretStream();
$_ENV[$fileKeyEnvironment] = $fileKey;
$_SERVER[$fileKeyEnvironment] = $fileKey;
putenv($fileKeyEnvironment . '=' . $fileKey);
$directFileProtector = new FileProtector();
$fileOptions = new ProtectionOptions('foundation.environment.file.v1', 'environment-file/v1');
$environmentFileProtector = new EnvironmentFileProtector(Foundation::cli([
    'app' => ['base_path' => $fileRoot],
]));

$subjects = [];

try {
    $subjects['direct_webrick_signed_url'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $directUrlGenerator->signed('download', ['id' => '42'], ['mode' => 'benchmark']),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_signed_url_policy'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationUrlGenerator->signed('download', ['id' => '42'], ['mode' => 'benchmark']),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_string_protection'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $directStringProtector->protectWithKeyRing(
            'JBSWY3DPEHPK3PXP',
            $mfaRing,
            $mfaOptions,
        ),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_mfa_protection'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationMfaProtector->protect($mfaFactor),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_purpose_token'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static function () use ($directPurposeToken): void {
            $token = $directPurposeToken->issue(['benchmark' => true], 'benchmark-account');
            if (!$directPurposeToken->verify($token)->verified) {
                throw new LogicException('Direct Epicrypt purpose-token benchmark verification failed.');
            }
        },
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_purpose_token'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static function () use ($foundationPurposeToken): void {
            $tokens = $foundationPurposeToken->forPurpose('benchmark', 300);
            $token = $tokens->issue(['benchmark' => true], 'benchmark-account');
            if (!$tokens->verify($token)->verified) {
                throw new LogicException('Foundation purpose-token benchmark verification failed.');
            }
        },
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_jwks'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $oauthFixture->keys->epicrypt->jwks(),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_jwks_publication'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationJwks->jwks(),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_file_protection'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static function () use ($directFileProtector, $fileRoot, $fileKey, $fileOptions): void {
            $output = $fileRoot . '/direct.encrypted';
            $directFileProtector->protect($fileRoot . '/source.env', $output, $fileKey, $fileOptions);
        },
        $fileOperations,
        $repetitions,
        $fileWarmup,
    );
    $subjects['foundation_environment_file_protection'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $environmentFileProtector->encrypt(
            input: 'source.env',
            output: 'foundation.encrypted',
            keyEnvironment: $fileKeyEnvironment,
            force: true,
        ),
        $fileOperations,
        $repetitions,
        $fileWarmup,
    );

    $subjects['direct_epicrypt_password_verification'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $passwordHasher->verifyPassword($password, $passwordHash),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_password_verification'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationPasswordVerifier->verify($password, $passwordHash),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_oauth_resource_validation'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $oauthFixture->resourceValidator->validate(
            $oauthToken,
            $oauthAudience,
            'GET',
            'https://benchmark-resource.example.test/resource',
        ),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_oauth_resource_validation'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $oauthFixture->accessValidator->verify($oauthToken, $oauthAudience),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_oidc_userinfo'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $directOidc->project(
            'account-1',
            $oauthRegistration->client->clientId,
            ['openid', 'email'],
        ),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_oidc_userinfo'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationOidc->project(
            'account-1',
            $oauthRegistration->client->clientId,
            ['openid', 'email'],
        ),
        $operations,
        $repetitions,
        $warmup,
    );

    $subjects['direct_epicrypt_pat_verification'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $directPat->verify($directPatToken),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_pat_db_verification'] = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $foundationPat->verify($foundationPatToken),
        $operations,
        $repetitions,
        $warmup,
    );

    $report = [
        'benchmark' => 'foundation-epicrypt-3.1-utilization',
        'versions' => [
            'php' => PHP_VERSION,
            'foundation' => InstalledVersions::getPrettyVersion('infocyph/foundation'),
            'epicrypt' => InstalledVersions::getPrettyVersion('infocyph/epicrypt'),
            'webrick' => InstalledVersions::getPrettyVersion('infocyph/webrick'),
        ],
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'operations_per_repetition' => $operations,
        'file_operations_per_repetition' => $fileOperations,
        'repetitions' => $repetitions,
        'warmup_operations' => $warmup,
        'subjects' => $subjects,
        'ratios' => epicrypt31Ratios($subjects),
        'attribution' => [
            'key_domains' => 'Signed URL and simple-token subjects include Foundation domain/config resolution over Epicrypt key derivation.',
            'mfa' => 'Foundation delta is factor/AAD mapping over Epicrypt StringProtector; durable DB I/O is excluded from this pair.',
            'jwks' => 'Foundation delta is OAuth/OIDC publication/deduplication policy over Epicrypt AsymmetricSigningKeySet JWKS.',
            'file_protection' => 'Foundation delta is path/key-locator/target policy over Epicrypt atomic FileProtector.',
            'oauth' => 'Foundation delta is client/authorization/account application-state checks over Epicrypt resource JWT validation.',
            'oidc' => 'Foundation delta is account-claim/subject mapping over Epicrypt UserInfo projection.',
            'pat' => 'Foundation uses Epicrypt PersonalAccessTokenManager directly; the measured delta is authoritative DBLayer persistence versus an in-memory protocol store.',
            'runtime' => 'Persistent-worker request overhead remains covered by benchmark:representative; OTP state/protection attribution remains covered by benchmark:otp.',
        ],
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
    ];

    file_put_contents(
        'php://stdout',
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );
} finally {
    $oauthFixture->close();
    $runtimeState->cleanup();
    DB::purge();

    foreach ([$signedEnvironment, $tokenEnvironment, $fileKeyEnvironment] as $environment) {
        unset($_ENV[$environment], $_SERVER[$environment]);
        putenv($environment);
    }

    foreach ([$patRoot, $fileRoot] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
