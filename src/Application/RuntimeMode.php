<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

enum RuntimeMode: string
{
    case Console = 'console';

    case Web = 'web';
}
