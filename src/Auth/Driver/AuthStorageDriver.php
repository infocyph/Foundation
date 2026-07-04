<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthStorageDriver: string
{
    case DBLAYER = 'dblayer';

    case MEMORY = 'memory';
}
