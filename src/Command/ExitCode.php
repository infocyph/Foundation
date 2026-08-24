<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final class ExitCode
{
    public const int CANNOT_EXECUTE = 126;

    public const int COMMAND_NOT_FOUND = 127;

    public const int FAILURE = 1;

    public const int INTERRUPTED = 130;

    public const int INVALID_USAGE = 2;

    public const int SUCCESS = 0;

    private function __construct() {}
}
