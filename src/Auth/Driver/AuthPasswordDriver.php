<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthPasswordDriver: string
{
    case EPICRYPT = 'epicrypt';

    case NATIVE = 'native';
}
