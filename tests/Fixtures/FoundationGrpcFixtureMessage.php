<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

/** Native gRPC wire fixture; supports the generated PHP stub message contract. */
final class FoundationGrpcFixtureMessage
{
    public function __construct(public string $payload = '') {}

    public function serializeToString(): string
    {
        return $this->payload;
    }

    public function mergeFromString(string $data): void
    {
        $this->payload = $data;
    }

    public static function decode(string $payload): self
    {
        return new self($payload);
    }
}
