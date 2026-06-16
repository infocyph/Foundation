<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

enum MfaStatus: string
{
    case ACTIVATED = 'activated';

    case CHALLENGE_ISSUED = 'challenge_issued';

    case ENROLLED = 'enrolled';

    case EXPIRED = 'expired';

    case INVALID = 'invalid';

    case RECOVERY_CODE_VERIFIED = 'recovery_code_verified';

    case REMOVED = 'removed';

    case VERIFIED = 'verified';
}
