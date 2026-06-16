<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Event;

interface EventDispatcherInterface
{
    public function dispatch(object $event): void;
}
