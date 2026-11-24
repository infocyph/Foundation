<?php

declare(strict_types=1);

namespace Infocyph;

use InvalidArgumentException;
use RuntimeException;

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

$port = filter_var(getenv('GRPC_TEST_PORT'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
if (!is_int($port)) {
    throw new RuntimeException('A valid dedicated loopback gRPC peer port is required.');
}
$stub = null;

try {
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
} finally {
    $stub?->close();
}

fwrite(STDOUT, "Foundation-selected native TalkingBytes gRPC loopback, failure mapping and channel cleanup verified.\n");
