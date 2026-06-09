<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthPasskeyDriver: string
{
    case MEMORY = 'memory';
    case WEBAUTHN = 'webauthn';
}
