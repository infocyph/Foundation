<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Emailer;

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

    private function createEmailer(Application $app): Emailer
    {
        $transport = (string) $app->config()->get('notifications.auth.transport', 'null');

        return match ($transport) {
            'log' => Emailer::usingLog(LogEmailConfig::fromArray([
                'dailyFiles' => (bool) $app->config()->get('notifications.auth.log.dailyFiles', true),
                'directory' => $this->notificationLogDirectory($app),
                'filenamePrefix' => (string) $app->config()->get('notifications.auth.log.filenamePrefix', 'auth'),
                'maxMessageBytes' => $app->config()->get('notifications.auth.log.maxMessageBytes'),
            ])),
            'mail' => Emailer::usingMailFunction(),
            default => Emailer::usingNull(),
        };
    }

    private function notificationLogDirectory(Application $app): string
    {
        $configured = $app->config()->get('notifications.auth.log.directory');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $basePath = rtrim((string) $app->config()->get('app.base_path', '.'), '/\\');
        $logsPath = trim((string) $app->config()->get('paths.logs', 'storage/logs'), '/\\');

        return $basePath . DIRECTORY_SEPARATOR . $logsPath . DIRECTORY_SEPARATOR . 'email';
    }
}
