<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthTokenDriver: string
{
    case EPICRYPT = 'epicrypt';

    case SIMPLE = 'simple';
}
