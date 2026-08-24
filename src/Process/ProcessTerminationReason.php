<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

enum ProcessTerminationReason: string
{
    case Cancelled = 'cancelled';

    case Exited = 'exited';

    case HeartbeatLost = 'heartbeat_lost';

    case IdleTimedOut = 'idle_timed_out';

    case Interrupted = 'interrupted';

    case IoError = 'io_error';

    case OutputLimit = 'output_limit';

    case TimedOut = 'timed_out';
}
