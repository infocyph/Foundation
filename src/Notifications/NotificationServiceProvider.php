<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(NotificationTemplateRegistry::class, fn() => new NotificationTemplateRegistry(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.notifications.emailer', fn() => $this->createEmailer($app), LifetimeEnum::Singleton);

        $container->bind(NotificationManager::class, fn() => new NotificationManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.notifications', fn() => $container->get(NotificationManager::class), LifetimeEnum::Singleton);
    }

    private function boolConfig(Application $app, string $key, bool $default): bool
    {
        $value = $app->config()->get($key, $default);

        return match (true) {
            is_bool($value) => $value,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            is_int($value) => $value !== 0,
            default => $default,
        };
    }

    private function createEmailer(Application $app): object
    {
        $emailerClass = 'Infocyph\\TalkingBytes\\Email\\Emailer';
        $logConfigClass = 'Infocyph\\TalkingBytes\\Email\\Config\\LogEmailConfig';

        if (!class_exists($emailerClass)) {
            throw new ConfigurationException(
                'Foundation notifications require infocyph/talkingbytes to resolve the emailer service.',
            );
        }

        $transport = $this->stringConfig($app, 'notifications.auth.transport', 'null');

        return match ($transport) {
            'log' => $this->requireObject($emailerClass::usingLog($logConfigClass::fromArray([
                'dailyFiles' => $this->boolConfig($app, 'notifications.auth.log.dailyFiles', true),
                'directory' => $this->notificationLogDirectory($app),
                'filenamePrefix' => $this->stringConfig($app, 'notifications.auth.log.filenamePrefix', 'auth'),
                'maxMessageBytes' => $app->config()->get('notifications.auth.log.maxMessageBytes'),
            ])), 'TalkingBytes Emailer'),
            'mail' => $this->requireObject($emailerClass::usingMailFunction(), 'TalkingBytes Emailer'),
            default => $this->requireObject($emailerClass::usingNull(), 'TalkingBytes Emailer'),
        };
    }

    private function notificationLogDirectory(Application $app): string
    {
        $configured = $app->config()->get('notifications.auth.log.directory');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $basePath = rtrim($this->stringConfig($app, 'app.base_path', '.'), '/\\');
        $logsPath = trim($this->stringConfig($app, 'paths.logs', 'storage/logs'), '/\\');

        return $basePath . DIRECTORY_SEPARATOR . $logsPath . DIRECTORY_SEPARATOR . 'email';
    }

    private function requireObject(mixed $value, string $context): object
    {
        if (!is_object($value)) {
            throw new ConfigurationException(sprintf('%s must resolve to an object.', $context));
        }

        return $value;
    }

    private function stringConfig(Application $app, string $key, string $default): string
    {
        $value = $app->config()->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
