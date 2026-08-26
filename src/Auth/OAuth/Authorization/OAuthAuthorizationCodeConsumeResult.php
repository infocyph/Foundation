<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

final readonly class OAuthAuthorizationCodeConsumeResult
{
    public function __construct(
        public OAuthAuthorizationCodeConsumeStatus $status,
        public ?OAuthAuthorizationCode $code = null,
    ) {}

    public function consumed(): bool
    {
        return $this->status === OAuthAuthorizationCodeConsumeStatus::Consumed
            && $this->code instanceof OAuthAuthorizationCode;
    }
}
