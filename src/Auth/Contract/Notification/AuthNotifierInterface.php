<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Notification;

use Infocyph\Foundation\Auth\Notification\AuthNotification;

interface AuthNotifierInterface
{
    public function send(AuthNotification $notification): void;
}
