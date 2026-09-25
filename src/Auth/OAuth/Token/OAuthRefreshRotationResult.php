<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

/** @deprecated Compatibility type for the legacy Foundation OAuth refresh flow. */
final readonly class OAuthRefreshRotationResult
{
    public function __construct(
        public OAuthRefreshRotationStatus $status,
        public ?OAuthRefreshTokenRecord $record = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === OAuthRefreshRotationStatus::Rotated;
    }
}
