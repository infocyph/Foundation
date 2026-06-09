<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

enum CacheDriver: string
{
    case APCU = 'apcu';
    case FILE = 'file';
    case LOCAL = 'local';
    case MEMORY = 'memory';
}
