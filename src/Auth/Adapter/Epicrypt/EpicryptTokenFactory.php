<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;
use Infocyph\Epicrypt\Token\Payload\SignedPayload;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface as AuthClockInterface;

final readonly class EpicryptTokenFactory
{
    public function __construct(
        private string $key,
        private AuthClockInterface $clock,
        private string $issuer,
        private string $audience,
        private SymmetricJwtAlgorithm $algorithm = SymmetricJwtAlgorithm::HS256,
        private int $maximumLifetimeSeconds = 1209600,
        private int $leewaySeconds = 0,
    ) {}

    public function audience(): string
    {
        return $this->audience;
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function jwtIssuer(string $type): SymmetricJwt
    {
        return SymmetricJwt::issuer(
            key: $this->key,
            type: $type,
            algorithm: $this->algorithm,
            clock: new EpicryptClockAdapter($this->clock),
        );
    }

    public function jwtVerifier(string $type): SymmetricJwt
    {
        return SymmetricJwt::verifier(
            key: $this->key,
            policy: new JwtPolicy(
                expectedIssuer: $this->issuer,
                expectedAudience: $this->audience,
                expectedType: $type,
                maximumLifetimeSeconds: $this->maximumLifetimeSeconds,
                leewaySeconds: $this->leewaySeconds,
            ),
            algorithm: $this->algorithm,
            clock: new EpicryptClockAdapter($this->clock),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function now(): int
    {
        return $this->clock->now();
    }

    public function payload(string $context): SignedPayload
    {
        return new SignedPayload(
            $context,
            new EpicryptClockAdapter($this->clock),
        );
    }
}
