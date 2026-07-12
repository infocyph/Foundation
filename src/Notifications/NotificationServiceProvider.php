<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\ExponentialBackoffRetryPolicy;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(NotificationTemplateRegistry::class, fn() => new NotificationTemplateRegistry(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind(EmailSenderFactory::class, new EmailSenderFactory(), LifetimeEnum::Singleton);
        $container->bind(EmailReceiverFactory::class, new EmailReceiverFactory(), LifetimeEnum::Singleton);
        $container->bind(EmailMailboxFactory::class, new EmailMailboxFactory(), LifetimeEnum::Singleton);
        $container->bind(EmailLimits::class, fn() => $this->emailLimits($app), LifetimeEnum::Singleton);
        $container->bind(RawEmailParser::class, fn() => new RawEmailParser(
            limits: $app->make(EmailLimits::class),
        ), LifetimeEnum::Singleton);
        $container->bind(BounceParser::class, new BounceParser(), LifetimeEnum::Singleton);
        $container->bind(AuthenticationResultsParser::class, new AuthenticationResultsParser(), LifetimeEnum::Singleton);

        $container->bind(Emailer::class, fn() => $this->createEmailer($app), LifetimeEnum::Singleton);
        $container->bind('foundation.notifications.emailer', fn() => $container->get(Emailer::class), LifetimeEnum::Singleton);

        $container->bind(NotificationManager::class, fn() => new NotificationManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.notifications', fn() => $container->get(NotificationManager::class), LifetimeEnum::Singleton);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function applyDkim(Application $app, Emailer $emailer): Emailer
    {
        $config = $this->arrayConfig($app, 'notifications.auth.dkim');
        if (!$this->boolValue($config, 'enabled', false)) {
            return $emailer;
        }

        $domain = $this->stringValue($config, 'domain');
        $selector = $this->stringValue($config, 'selector');
        $privateKey = $this->stringValue($config, 'private_key');
        $privateKeyPath = $this->stringValue($config, 'private_key_path');
        $headers = $this->stringList($config['headers'] ?? []);
        $algorithm = DkimAlgorithm::tryFrom($this->stringValue($config, 'algorithm', DkimAlgorithm::RsaSha256->value))
            ?? DkimAlgorithm::RsaSha256;

        $dkim = $privateKeyPath !== ''
            ? DkimConfig::fromPrivateKeyPath($domain, $selector, $this->absolute($privateKeyPath) ? $privateKeyPath : $app->basePath($privateKeyPath), $headers, $algorithm)
            : DkimConfig::fromPrivateKeyString($domain, $selector, $privateKey, $headers, $algorithm);

        return $emailer->withDkim($dkim);
    }

    private function applyRateLimit(Application $app, Emailer $emailer): Emailer
    {
        $config = $this->arrayConfig($app, 'notifications.auth.rate_limit');
        if (!$this->boolValue($config, 'enabled', false)) {
            return $emailer;
        }

        return $emailer->withRateLimit(new RateLimiter(
            $this->intValue($config, 'max_requests', 60),
            $this->intValue($config, 'per_seconds', 60),
        ));
    }

    private function applyRetry(Application $app, Emailer $emailer): Emailer
    {
        $config = $this->arrayConfig($app, 'notifications.auth.retry');
        if (!$this->boolValue($config, 'enabled', false)) {
            return $emailer;
        }

        $policy = match ($this->stringValue($config, 'policy', 'fixed')) {
            'backoff', 'exponential' => new ExponentialBackoffRetryPolicy(
                $this->intValue($config, 'max_attempts', 3),
                $this->intValue($config, 'delay_ms', 250),
            ),
            default => new FixedDelayRetryPolicy(
                $this->intValue($config, 'max_attempts', 3),
                $this->intValue($config, 'delay_ms', 250),
            ),
        };

        return $emailer->withRetry($policy);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayConfig(Application $app, string $key): array
    {
        return ValueNormalizer::associativeArray(
            $app->config()->get($key, []),
        );
    }

    private function baseEmailer(Application $app, string $transport): Emailer
    {
        $config = $this->transportConfig($app, $transport);

        return match ($transport) {
            'fake' => Emailer::fake(),
            'log' => Emailer::usingLog(LogEmailConfig::fromArray([
                'dailyFiles' => $this->boolValue($config, 'dailyFiles', true),
                'directory' => $this->notificationLogDirectory($app, $config),
                'filenamePrefix' => $this->stringValue($config, 'filenamePrefix', 'auth'),
                'maxMessageBytes' => $config['maxMessageBytes'] ?? null,
            ])),
            'mail' => Emailer::usingMailFunction(),
            'sendmail' => Emailer::usingSendmail(SendmailConfig::fromArray($config)),
            'smtp' => Emailer::usingSmtp(SmtpConfig::fromArray($config)),
            'spool' => Emailer::usingSpool(SpoolConfig::fromArray($this->normalizeSpoolConfig($app, $config))),
            default => Emailer::usingNull(),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boolValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $default,
        };
    }

    private function createEmailer(Application $app): Emailer
    {
        return $this->decorateEmailer(
            $app,
            $this->baseEmailer($app, $this->stringConfig($app, 'notifications.auth.transport', 'null')),
        );
    }

    /**
     */
    private function decorateEmailer(Application $app, Emailer $emailer, bool $includeFallback = true): Emailer
    {
        $emailer = $this->applyRetry($app, $emailer);
        $emailer = $this->applyRateLimit($app, $emailer);
        $emailer = $this->applyDkim($app, $emailer);

        if (!$includeFallback) {
            return $emailer;
        }

        $fallbackTransports = [];

        foreach ($this->stringList($app->config()->get('notifications.auth.fallback.transports', [])) as $transport) {
            if ($transport === $this->stringConfig($app, 'notifications.auth.transport', 'null')) {
                continue;
            }

            $fallbackTransports[] = $this->decorateEmailer(
                $app,
                $this->baseEmailer($app, $transport),
                false,
            )->transport();
        }

        return $fallbackTransports === []
            ? $emailer
            : $emailer->withFallback($fallbackTransports);
    }

    private function emailLimits(Application $app): EmailLimits
    {
        $config = $this->arrayConfig($app, 'notifications.email.parsing.limits');

        return new EmailLimits(
            maxMessageBytes: $this->intValue($config, 'maxMessageBytes', 10 * 1024 * 1024),
            maxAttachmentBytes: $this->intValue($config, 'maxAttachmentBytes', 25 * 1024 * 1024),
            maxAttachmentCount: $this->intValue($config, 'maxAttachmentCount', 500),
            maxDecodedBodyBytes: $this->intValue($config, 'maxDecodedBodyBytes', 10 * 1024 * 1024),
            maxMimeDepth: $this->intValue($config, 'maxMimeDepth', 20),
            maxMimeParts: $this->intValue($config, 'maxMimeParts', 500),
            maxHeaderBytes: $this->intValue($config, 'maxHeaderBytes', 131072),
            maxHeaderCount: $this->intValue($config, 'maxHeaderCount', 2000),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : $default;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeSpoolConfig(Application $app, array $config): array
    {
        $directory = $config['directory'] ?? null;
        if (is_string($directory) && $directory !== '' && !$this->absolute($directory)) {
            $config['directory'] = $app->basePath($directory);
        }

        $processingDirectory = $config['processingDirectory'] ?? null;
        if (is_string($processingDirectory) && $processingDirectory !== '' && !$this->absolute($processingDirectory)) {
            $config['processingDirectory'] = $app->basePath($processingDirectory);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function notificationLogDirectory(Application $app, array $config): string
    {
        $configured = $config['directory'] ?? $app->config()->get('notifications.auth.log.directory');
        if (is_string($configured) && $configured !== '') {
            return $this->absolute($configured)
                ? $configured
                : $app->basePath($configured);
        }

        return $app->logsPath('email');
    }

    private function stringConfig(Application $app, string $key, string $default): string
    {
        $value = $app->config()->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringValue(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value)
            ? $value
            : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function transportConfig(Application $app, string $transport): array
    {
        $configured = ValueNormalizer::associativeArray(
            $app->config()->get('notifications.auth.transports.' . $transport),
        );
        if ($configured !== []) {
            return $configured;
        }

        return ValueNormalizer::associativeArray(
            $app->config()->get('notifications.auth.' . $transport),
        );
    }
}
