<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

enum DeviceTrustStatus: string
{
    case REVOKED = 'revoked';

    case TRUSTED = 'trusted';

    case UNTRUSTED = 'untrusted';
}
