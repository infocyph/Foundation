<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use ReflectionMethod;

final readonly class FoundationGrpcFixtureStub
{
    private \Grpc\BaseStub $inner;

    public function __construct(int $port)
    {
        $this->inner = new \Grpc\BaseStub(
            '127.0.0.1:' . $port,
            ['credentials' => \Grpc\ChannelCredentials::createInsecure()],
        );
    }

    public function call(string $method, FoundationGrpcFixtureMessage $message, ?float $deadline): object
    {
        $options = $deadline === null ? [] : ['timeout' => GrpcDeadline::secondsToMicros($deadline)];

        return (new ReflectionMethod($this->inner, '_simpleRequest'))->invokeArgs($this->inner, [
            '/foundation.Integration/' . $method,
            $message,
            [FoundationGrpcFixtureMessage::class, 'decode'],
            [],
            $options,
        ]);
    }
}
