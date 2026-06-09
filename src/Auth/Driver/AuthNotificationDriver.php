<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthNotificationDriver: string
{
    case COLLECT = 'collect';
    case TALKINGBYTES = 'talkingbytes';
}
