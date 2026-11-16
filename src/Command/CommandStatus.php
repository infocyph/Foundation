<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

/**
 * Public command lifecycle status values.
 *
 * @api
 */
enum CommandStatus: string
{
    case Cancelled = 'cancelled';

    case Failed = 'failed';

    case Pending = 'pending';

    case Running = 'running';

    case Succeeded = 'succeeded';

    case TimedOut = 'timed_out';

    case Waiting = 'waiting';
}
