<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthPasswordDriver: string
{
    case NATIVE = 'native';

    case SECURITY = 'security';
}
