<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\AuthLayer\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Container;

final readonly class NotificationManager
{
    public function __construct(
        private ConfigRepository $config,
        private Container $container,
    ) {}

    public function authNotifier(): AuthNotifierInterface
    {
        return $this->container->get(AuthNotifierInterface::class);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('notifications', []);
        }

        return $this->config->get('notifications.' . $key, $default);
    }
}
