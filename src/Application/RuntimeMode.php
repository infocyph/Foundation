<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

enum RuntimeMode: string
{
    case Cli = 'cli';

    case Scheduler = 'scheduler';

    case Web = 'web';

    case Worker = 'worker';

    public function isPersistent(): bool
    {
        return $this === self::Worker || $this === self::Scheduler;
    }
}
