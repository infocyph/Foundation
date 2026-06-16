<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

enum PasskeyRegistrationStatus: string
{
    case INVALID = 'invalid';

    case REGISTERED = 'registered';

    case STARTED = 'started';
}
