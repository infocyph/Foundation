<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\StepUp;

enum StepUpMethod: string
{
    case MFA = 'mfa';

    case PASSKEY = 'passkey';

    case PASSWORD = 'password';

    case RECENT_AUTH = 'recent_auth';
}
