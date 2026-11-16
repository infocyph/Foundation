<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

enum DeviceStatus: string
{
    case NOT_FOUND = 'not_found';

    case REGISTERED = 'registered';

    case REJECTED = 'rejected';

    case REVOKED = 'revoked';

    case TOUCHED = 'touched';

    case TRUSTED = 'trusted';
}
