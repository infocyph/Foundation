<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Event\EventDispatcherInterface;

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): void {}
}
