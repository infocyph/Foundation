<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

enum PasskeyChallengePurpose: string
{
    case AUTHENTICATION = 'authentication';

    case REGISTRATION = 'registration';

    case STEP_UP = 'step_up';
}
