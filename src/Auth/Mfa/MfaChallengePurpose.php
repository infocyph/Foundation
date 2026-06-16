<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

enum MfaChallengePurpose: string
{
    case ENROLLMENT = 'enrollment';

    case FACTOR_REMOVAL = 'factor_removal';

    case LOGIN = 'login';

    case STEP_UP = 'step_up';
}
