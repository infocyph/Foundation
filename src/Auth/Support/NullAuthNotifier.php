<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;

final class NullAuthNotifier implements AuthNotifierInterface
{
    public function send(AuthNotification $notification): void {}
}
