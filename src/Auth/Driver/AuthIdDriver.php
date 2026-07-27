<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthIdDriver: string
{
    case RANDOM = 'random';

    case UID = 'uid';
}
