<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

enum OverlapMode: string
{
    case Allow = 'allow';
    case Skip = 'skip';
    case Wait = 'wait';
}
