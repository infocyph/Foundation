<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class NotificationChannelRegistry
{
    /** @param \Closure(string):mixed $resolver */
    public function __construct(
        private ConfigRepository $config,
        private ?NotificationChannel $mail,
        private \Closure $resolver,
    ) {}

    public function channel(string $name): NotificationChannel
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Notification channel name must be non-empty.');
        }

        return $this->resolve($name);
    }

    private function normalize(string $name, mixed $definition): NotificationChannel
    {
        if ($definition instanceof NotificationChannel) {
            return $definition;
        }
        if (!is_string($definition) || trim($definition) === '') {
            throw new \InvalidArgumentException(sprintf(
                'Notification channel "%s" must be a service class name or %s instance.',
                $name,
                NotificationChannel::class,
            ));
        }

        $channel = ($this->resolver)($definition);
        if (!$channel instanceof NotificationChannel) {
            throw new \InvalidArgumentException(sprintf(
                'Notification channel "%s" service "%s" must implement %s.',
                $name,
                $definition,
                NotificationChannel::class,
            ));
        }

        return $channel;
    }

    private function resolve(string $name): NotificationChannel
    {
        $configured = $this->config->get('notifications.channels', []);
        if (!is_array($configured)) {
            throw new \LogicException('notifications.channels must be a channel map.');
        }

        if (array_key_exists($name, $configured)) {
            return $this->normalize($name, $configured[$name]);
        }
        if ($name === 'mail') {
            return $this->mail ?? throw new \LogicException(
                'The mail notification channel requires the communication module; run "php infbyte module:install communication".',
            );
        }

        throw new \InvalidArgumentException(sprintf(
            'Notification channel "%s" is not configured.',
            $name,
        ));
    }
}
