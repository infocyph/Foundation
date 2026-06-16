<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface as AuthClockInterface;
use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Validation\ExpectedJwtClaims;
use Infocyph\Epicrypt\Token\Jwt\Validation\JwtValidationOptions;
use Infocyph\Epicrypt\Token\Jwt\Validation\RequiredJwtClaims;
use Infocyph\Epicrypt\Token\Payload\SignedPayload;

final readonly class EpicryptTokenFactory
{
    public function __construct(
        private string $key,
        private AuthClockInterface $clock,
        private ?string $issuer = null,
        private ?string $audience = null,
        private int $leewaySeconds = 0,
    ) {}

    public function audience(): ?string
    {
        return $this->normalize($this->audience);
    }

    public function issuer(): ?string
    {
        return $this->normalize($this->issuer);
    }

    public function jwt(bool $requireTokenId = false): SymmetricJwt
    {
        return new SymmetricJwt(
            expectedClaims: new ExpectedJwtClaims(
                issuer: $this->issuer(),
                audience: $this->audience(),
                required: new RequiredJwtClaims(
                    subject: true,
                    jwtId: $requireTokenId,
                ),
            ),
            validationOptions: new JwtValidationOptions(
                leewaySeconds: max(0, $this->leewaySeconds),
            ),
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

    private function normalize(?string $value): ?string
    {
        return $value !== null && $value !== ''
            ? $value
            : null;
    }
}
