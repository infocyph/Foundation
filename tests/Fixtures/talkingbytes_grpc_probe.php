<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Tests\Fixtures\FoundationGrpcFixtureMessage;
use Infocyph\Foundation\Tests\Fixtures\FoundationGrpcFixtureStub;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!extension_loaded('grpc') || !class_exists('Grpc\\BaseStub')) {
    throw new RuntimeException('Native gRPC extension and generated-stub package are required.');
}

$root = sys_get_temp_dir() . '/foundation-grpc-network-' . bin2hex(random_bytes(5));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Unable to allocate the gRPC test state directory.');
}
$ready = $root . '/ready.json';
$python = getenv('GRPC_PYTHON_BINARY');
if (!is_string($python) || $python === '') {
    throw new RuntimeException('A dedicated Python grpcio binary must be configured.');
}
$process = proc_open(
    [$python, __DIR__ . '/talkingbytes_grpc_server.py', $ready],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to spawn the real gRPC server.');
}
fclose($pipes[0]);
stream_set_blocking($pipes[2], false);

try {
    $until = hrtime(true) + 10_000_000_000;
    $port = null;
    do {
        clearstatcache(true, $ready);
        $decoded = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
        $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
        if (is_int($port) && $port > 0 && $port <= 65535) {
            break;
        }
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(20_000);
    } while (hrtime(true) < $until);
    if (!is_int($port) || $port < 1 || $port > 65535) {
        $diagnostics = trim((string) stream_get_contents($pipes[2]));
        throw new RuntimeException('Real gRPC server did not become ready.'
            . ($diagnostics === '' ? '' : ' Child stderr: ' . $diagnostics));
    }

    $app = Foundation::worker([
        'app' => ['base_path' => dirname(__DIR__, 2), 'load_env' => false, 'capabilities' => ['communication']],
        '_config_cache' => false,
        'communication' => [
            'grpc' => [
                'default_profile' => 'native',
                'profiles' => ['native' => ['retry' => ['enabled' => false]]],
            ],
        ],
    ])->boot();

    $stub = new FoundationGrpcFixtureStub($port);
    $invoker = new class ($stub) implements NativeGrpcInvoker {
        public function __construct(private FoundationGrpcFixtureStub $stub) {}

        /**
         * @throws \InvalidArgumentException
         */
        /**
         * @throws \UnexpectedValueException
         * @throws \InvalidArgumentException
         */
        #[\Override]
        public function invoke(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            if (!$message instanceof FoundationGrpcFixtureMessage) {
                throw new InvalidArgumentException('Native fixture only accepts typed gRPC payloads.');
            }
            unset($headers);
            $suffix = str_ends_with($method, '/Unary') ? 'Unary' : 'Missing';

            return $this->stub->call($suffix, $message, $deadlineSeconds);
        }
    };

    $result = $app->execution()->run(static function () use ($app, $invoker): array {
        $client = $app->make(CommunicationProfiles::class)->grpcNative($invoker);

        return [
            $client->send(new GrpcRequest(
                '/foundation.Integration/Unary',
                new FoundationGrpcFixtureMessage('foundation-native'),
                deadlineSeconds: 3.0,
            )),
            $client->send(new GrpcRequest(
                '/foundation.Integration/Missing',
                new FoundationGrpcFixtureMessage('invalid-method'),
                deadlineSeconds: 3.0,
            )),
        ];
    });

    [$accepted, $rejected] = $result;
    if (!$accepted->successful
        || !$accepted->response instanceof GrpcResponse
        || !$accepted->response->message instanceof FoundationGrpcFixtureMessage
        || $accepted->response->message->payload !== 'echo:foundation-native'
    ) {
        $response = $accepted->response;
        $message = $response instanceof GrpcResponse ? $response->message : null;
        $diagnostic = json_encode([
            'successful' => $accepted->successful,
            'status' => $accepted->statusCode,
            'error' => $accepted->error,
            'response_class' => get_debug_type($response),
            'message_class' => get_debug_type($message),
            'response_status' => $response instanceof GrpcResponse ? $response->status->name : null,
            'response_metadata' => $response instanceof GrpcResponse ? $response->metadata : [],
            'fixture_payload' => $message instanceof FoundationGrpcFixtureMessage ? $message->payload : null,
        ], JSON_THROW_ON_ERROR);

        throw new RuntimeException('Real Foundation-selected native gRPC unary roundtrip failed: ' . $diagnostic);
    }
    if ($rejected->successful || $rejected->statusCode === 0) {
        throw new RuntimeException('Native gRPC UNIMPLEMENTED status was incorrectly reported as success.');
    }

    fwrite(STDOUT, "Foundation-selected native TalkingBytes gRPC loopback and failure mapping verified.\n");
} finally {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if (is_file($ready)) {
        unlink($ready);
    }
    rmdir($root);
}
