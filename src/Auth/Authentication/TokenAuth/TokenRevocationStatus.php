<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

enum TokenRevocationStatus: string
{
    case ALREADY_REVOKED = 'already_revoked';

    case REVOKED = 'revoked';
}
