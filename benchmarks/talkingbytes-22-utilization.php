<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Grpc\GrpcClientFactory;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\Webhook;

require dirname(__DIR__) . '/vendor/autoload.php';
$operations = max(100, (int) (getenv('TALKINGBYTES_RUNTIME_OPERATIONS') ?: 2_000));
$repetitions = max(3, (int) (getenv('TALKINGBYTES_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(20, (int) (getenv('TALKINGBYTES_RUNTIME_WARMUP') ?: 100));

$httpConfig = [
    'timeoutSeconds' => 10,
    'connectTimeoutSeconds' => 10,
    'followRedirects' => false,
    'maxRedirects' => 5,
    'verifyPeer' => true,
    'verifyHost' => true,
    'cookies' => ['enabled' => true],
    'retry' => ['enabled' => false],
    'rate_limit' => ['enabled' => false],
    'circuit_breaker' => ['enabled' => false],
    'idempotency' => ['enabled' => false],
];
$webhookSecret = 'foundation-talkingbytes-benchmark-secret';
$webhookInbound = [
    'secret' => $webhookSecret,
    'max_age_seconds' => 300,
    'max_payload_bytes' => 1_048_576,
    'replay' => ['enabled' => false],
];
$grpcConfig = [
    'retry' => ['enabled' => false],
];
$emailResolved = [
    'transport' => ['driver' => 'fake'],
    'fallbacks' => [],
    'retry' => ['enabled' => false],
    'rate_limit' => ['enabled' => false],
    'dkim' => ['enabled' => false],
];

$config = new ConfigRepository([
    'communication' => [
        'http' => [
            'default_client' => 'benchmark',
            'clients' => ['benchmark' => $httpConfig],
        ],
        'webhooks' => [
            'default_inbound' => 'benchmark',
            'inbound' => ['benchmark' => $webhookInbound],
        ],
        'grpc' => [
            'default_profile' => 'benchmark',
            'profiles' => ['benchmark' => $grpcConfig],
        ],
    ],
    'notifications' => [
        'email' => [
            'default_sender' => 'benchmark',
            'senders' => [
                'benchmark' => ['transport' => 'fake'],
            ],
            'transports' => [
                'fake' => ['driver' => 'fake'],
            ],
        ],
    ],
]);

$communicationProfiles = new CommunicationProfiles($config);
$emailFactory = new EmailSenderFactory();
$emailProfiles = new EmailProfiles(
    $config,
    new PathManager(sys_get_temp_dir()),
    $emailFactory,
    new EmailReceiverFactory(),
    new EmailMailboxFactory(),
    new RawEmailParser(),
);
$grpcFactory = new GrpcClientFactory();
$grpcCaller = static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
    GrpcStatus::Ok,
    $request->message,
);

$subjects = [
    'direct_http_resolved_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => HttpClient::fromResolvedConfig($httpConfig),
        $operations,
        $repetitions,
        $warmup,
    ),
    'foundation_http_profile_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $communicationProfiles->http('benchmark'),
        $operations,
        $repetitions,
        $warmup,
    ),
    'direct_webhook_verifier_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => Webhook::verifierFromResolvedConfig($webhookSecret, $webhookInbound),
        $operations,
        $repetitions,
        $warmup,
    ),
    'foundation_webhook_profile_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $communicationProfiles->webhookVerifier('benchmark'),
        $operations,
        $repetitions,
        $warmup,
    ),
    'direct_grpc_client_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $grpcFactory->using($grpcCaller, $grpcConfig),
        $operations,
        $repetitions,
        $warmup,
    ),
    'foundation_grpc_profile_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $communicationProfiles->grpc($grpcCaller, 'benchmark'),
        $operations,
        $repetitions,
        $warmup,
    ),
    'direct_email_sender_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $emailFactory->fromResolvedConfig($emailResolved),
        $operations,
        $repetitions,
        $warmup,
    ),
    'foundation_email_profile_construct' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::measure(
        static fn() => $emailProfiles->sender('benchmark'),
        $operations,
        $repetitions,
        $warmup,
    ),
];

$report = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'metadata' => [
        'suite' => 'foundation-talkingbytes-2.2-utilization',
        'talkingbytes' => InstalledVersions::getPrettyVersion('infocyph/talkingbytes') ?? 'unknown',
        'boundary' => 'TalkingBytes owns protocol/resolved-composition mechanics; Foundation adds named application profiles, path/secret policy and DI lifetime selection.',
    ],
    'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
    'operations_per_repetition' => $operations,
    'repetitions' => $repetitions,
    'subjects' => $subjects,
    'ratios' => [
        'foundation_http_profile_vs_direct_talkingbytes' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::ratio(
            $subjects['foundation_http_profile_construct']['median_ns'],
            $subjects['direct_http_resolved_construct']['median_ns'],
        ),
        'foundation_webhook_profile_vs_direct_talkingbytes' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::ratio(
            $subjects['foundation_webhook_profile_construct']['median_ns'],
            $subjects['direct_webhook_verifier_construct']['median_ns'],
        ),
        'foundation_grpc_profile_vs_direct_talkingbytes' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::ratio(
            $subjects['foundation_grpc_profile_construct']['median_ns'],
            $subjects['direct_grpc_client_construct']['median_ns'],
        ),
        'foundation_email_profile_vs_direct_talkingbytes' => \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::ratio(
            $subjects['foundation_email_profile_construct']['median_ns'],
            $subjects['direct_email_sender_construct']['median_ns'],
        ),
    ],
    'attribution' => [
        'http' => 'Both subjects use TalkingBytes 2.2 resolved HTTP composition; Foundation adds named profile lookup plus production TLS policy.',
        'webhook' => 'Both subjects use TalkingBytes 2.2 verifier composition; Foundation adds named profile lookup and application secret policy.',
        'grpc' => 'Both subjects use TalkingBytes GrpcClientFactory; Foundation adds named retry-profile selection.',
        'email' => 'Both subjects use TalkingBytes EmailSenderFactory::fromResolvedConfig(); Foundation adds sender/transport lookup plus application path/secret resolution.',
        'native_protocol_benchmarks' => 'HTTP transport, webhook crypto/delivery, gRPC transport/streaming, email protocol/network and parser microbenchmarks remain TalkingBytes-owned and are intentionally not duplicated here.',
    ],
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
];

$build = dirname(__DIR__) . '/build';
if (!is_dir($build) && !mkdir($build, 0777, true) && !is_dir($build)) {
    throw new RuntimeException('Unable to create TalkingBytes benchmark output directory.');
}

$encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($build . '/talkingbytes-2.2-benchmark.json', $encoded . PHP_EOL);
fwrite(STDOUT, $encoded . PHP_EOL);
