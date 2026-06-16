<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

enum PasskeyAuthenticationStatus: string
{
    case INVALID = 'invalid';

    case STARTED = 'started';

    case VERIFIED = 'verified';
}
