<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

enum TokenType: string
{
    case ACCESS = 'access';

    case REFRESH = 'refresh';
}
