<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

enum RuntimeMode: string
{
    case Web = 'web';

    case Cli = 'cli';

    case Worker = 'worker';

    case Scheduler = 'scheduler';

    public function isPersistent(): bool
    {
        return $this === self::Worker || $this === self::Scheduler;
    }
}
