<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthStorageDriver: string
{
    case DATABASE = 'database';

    case MEMORY = 'memory';
}
