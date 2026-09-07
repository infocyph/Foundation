<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

/**
 * @deprecated OTP 6.1 owns WebAuthn ceremony parsing, serialization and validation.
 *
 * Retained temporarily as a compatibility/cold-path sentinel for Foundation 3
 * integration checks. It must not acquire WebAuthn runtime state or services.
 */
final class WebAuthnRuntime
{
}
