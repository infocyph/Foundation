<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthCacheDriver: string
{
    case ARRAY = 'array';
    case CACHELAYER = 'cachelayer';
}
