<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use ReflectionMethod;

final readonly class FoundationGrpcFixtureStub
{
    private object $inner;

    /**
     * @throws \RuntimeException
     */
    public function __construct(int $port)
    {
        // The selected extension lane supplies these optional classes. Reflection
        // keeps the ordinary test graph independent of ext-grpc declarations.
        $credentials = (new ReflectionMethod(self::nativeClass('Grpc\\ChannelCredentials'), 'createInsecure'))->invoke(null);
        $this->inner = (new \ReflectionClass(self::nativeClass('Grpc\\BaseStub')))->newInstance(
            '127.0.0.1:' . $port,
            ['credentials' => $credentials],
        );
    }

    /**
     * @throws \UnexpectedValueException
     */
    public function call(string $method, FoundationGrpcFixtureMessage $message, ?float $deadline): \Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult
    {
        $options = $deadline === null ? [] : ['timeout' => GrpcDeadline::secondsToMicros($deadline)];

        $call = (new ReflectionMethod($this->inner, '_simpleRequest'))->invokeArgs($this->inner, [
            '/foundation.Integration/' . $method,
            $message,
            [FoundationGrpcFixtureMessage::class, 'decode'],
            [],
            $options,
        ]);
        if (!is_object($call)) {
            throw new \UnexpectedValueException('Native gRPC stub did not return a call object.');
        }

        $result = (new ReflectionMethod($call, 'wait'))->invoke($call);
        if (!is_array($result) || !array_key_exists(0, $result) || !is_object($result[1] ?? null)) {
            throw new \UnexpectedValueException('Native gRPC wait did not return a reply and status.');
        }
        $status = get_object_vars($result[1]);
        $code = $status['code'] ?? 13;
        $details = $status['details'] ?? '';
        if (!is_int($code) || !is_string($details)) {
            throw new \UnexpectedValueException('Native gRPC status requires an integer code and string details.');
        }

        return new \Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult(
            statusCode: $code,
            message: $result[0],
            metadata: ['fixture_status_details' => $details],
        );
    }

    /**
     * @return class-string
     * @throws \RuntimeException
     */
    private static function nativeClass(string $class): string
    {
        if (!class_exists($class)) {
            throw new \RuntimeException('The selected native gRPC fixture requires ' . $class . '.');
        }

        return $class;
    }
}
