<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\ExponentialBackoffRetryPolicy;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

/**
 * Maps Foundation application email profiles to native TalkingBytes objects.
 *
 * TalkingBytes owns email transport, parsing, receiving and mailbox behavior.
 * Foundation owns application profile selection and path resolution.
 */
final readonly class EmailProfiles
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
        private EmailSenderFactory $senders,
        private EmailReceiverFactory $receivers,
        private EmailMailboxFactory $mailboxes,
        private RawEmailParser $parser,
    ) {}

    public function authEmailer(): Emailer
    {
        $transport = $this->stringConfig('notifications.auth.transport', 'null');
        $emailer = $this->decorate($this->emailerFor($transport), includeFallback: true);

        return $emailer;
    }

    public function imapMailbox(string $profile = 'default'): Mailbox
    {
        return $this->mailboxes->usingImap(ImapConfig::fromArray(
            $this->profile('notifications.email.mailboxes.imap', $profile),
        ));
    }

    public function pop3Mailbox(string $profile = 'default'): Pop3Mailbox
    {
        return $this->mailboxes->usingPop3(Pop3Config::fromArray(
            $this->profile('notifications.email.mailboxes.pop3', $profile),
        ));
    }

    public function spoolReceiver(string $profile = 'default'): SpoolEmailReceiver
    {
        $config = $this->resolveSpoolPaths(
            $this->profile('notifications.email.receivers.spool', $profile),
        );

        return $this->receivers->usingSpool(
            SpoolConfig::fromArray($config),
            $this->parser,
            ValueNormalizer::bool($config['deleteAfterRead'] ?? false, false),
            $this->nullableString($config['moveAfterRead'] ?? null),
            $this->nullableString($config['failedDirectory'] ?? null),
        );
    }

    private function applyDkim(Emailer $emailer): Emailer
    {
        $config = $this->section('notifications.auth.dkim');
        if (!ValueNormalizer::bool($config['enabled'] ?? false, false)) {
            return $emailer;
        }

        $domain = $this->requiredString($config, 'domain');
        $selector = $this->requiredString($config, 'selector');
        $headers = ValueNormalizer::stringList($config['headers'] ?? []);
        $algorithm = DkimAlgorithm::tryFrom($this->string($config, 'algorithm', DkimAlgorithm::RsaSha256->value))
            ?? throw new \InvalidArgumentException('Unsupported DKIM algorithm.');
        $privateKeyPath = $this->nullableString($config['private_key_path'] ?? null);
        $privateKey = $this->nullableString($config['private_key'] ?? null);

        if ($privateKeyPath !== null && $privateKey !== null) {
            throw new \InvalidArgumentException('Configure either notifications.auth.dkim.private_key or private_key_path, not both.');
        }
        if ($privateKeyPath === null && $privateKey === null) {
            throw new \InvalidArgumentException('DKIM signing requires a private key or private key path.');
        }

        $dkim = $privateKeyPath !== null
            ? DkimConfig::fromPrivateKeyPath(
                $domain,
                $selector,
                $this->absolutePath($privateKeyPath),
                $headers,
                $algorithm,
            )
            : DkimConfig::fromPrivateKeyString($domain, $selector, $privateKey ?? '', $headers, $algorithm);

        return $emailer->withDkim($dkim);
    }

    private function applyRateLimit(Emailer $emailer): Emailer
    {
        $config = $this->section('notifications.auth.rate_limit');
        if (!ValueNormalizer::bool($config['enabled'] ?? false, false)) {
            return $emailer;
        }

        return $emailer->withRateLimit(new RateLimiter(
            ValueNormalizer::int($config['max_requests'] ?? 60, 60),
            ValueNormalizer::int($config['per_seconds'] ?? 60, 60),
        ));
    }

    private function applyRetry(Emailer $emailer): Emailer
    {
        $config = $this->section('notifications.auth.retry');
        if (!ValueNormalizer::bool($config['enabled'] ?? false, false)) {
            return $emailer;
        }

        $attempts = ValueNormalizer::int($config['max_attempts'] ?? 3, 3);
        $delay = ValueNormalizer::int($config['delay_ms'] ?? 250, 250);
        $policy = match ($config['policy'] ?? 'fixed') {
            'backoff', 'exponential' => new ExponentialBackoffRetryPolicy($attempts, $delay),
            'fixed' => new FixedDelayRetryPolicy($attempts, $delay),
            default => throw new \InvalidArgumentException('Unsupported notification retry policy.'),
        };

        return $emailer->withRetry($policy);
    }

    private function decorate(Emailer $emailer, bool $includeFallback): Emailer
    {
        $emailer = $this->applyDkim($this->applyRateLimit($this->applyRetry($emailer)));
        if (!$includeFallback) {
            return $emailer;
        }

        $primary = $this->stringConfig('notifications.auth.transport', 'null');
        $fallbacks = [];
        foreach (ValueNormalizer::stringList($this->config->get('notifications.auth.fallback.transports', [])) as $transport) {
            if ($transport === $primary) {
                continue;
            }

            $fallbacks[] = $this->decorate($this->emailerFor($transport), false)->transport();
        }

        return $fallbacks === [] ? $emailer : $emailer->withFallback($fallbacks);
    }

    private function emailerFor(string $transport): Emailer
    {
        $config = $this->transportConfig($transport);

        return match ($transport) {
            'fake' => $this->senders->fake(),
            'log' => $this->senders->usingLog(LogEmailConfig::fromArray([
                'dailyFiles' => ValueNormalizer::bool($config['dailyFiles'] ?? true, true),
                'directory' => $this->logDirectory($config),
                'filenamePrefix' => $this->string($config, 'filenamePrefix', 'auth'),
                'maxMessageBytes' => $config['maxMessageBytes'] ?? null,
            ])),
            'mail' => $this->senders->usingMailFunction(),
            'null' => $this->senders->usingNull(),
            'sendmail' => $this->senders->usingSendmail(SendmailConfig::fromArray($config)),
            'smtp' => $this->senders->usingSmtp(SmtpConfig::fromArray($config)),
            'spool' => $this->senders->usingSpool(SpoolConfig::fromArray($this->resolveSpoolPaths($config))),
            default => throw new \InvalidArgumentException(sprintf('Unsupported notification email transport "%s".', $transport)),
        };
    }

    private function absolutePath(string $path): string
    {
        return $this->absolute($path) ? rtrim($path, DIRECTORY_SEPARATOR) : $this->paths->base($path);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function logDirectory(array $config): string
    {
        $configured = $config['directory'] ?? $this->config->get('notifications.auth.log.directory');
        if (is_string($configured) && trim($configured) !== '') {
            return $this->absolutePath($configured);
        }

        return $this->paths->logs('email');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string, mixed> */
    private function profile(string $collectionKey, string $profile): array
    {
        $name = trim($profile);
        if ($name === '') {
            throw new \InvalidArgumentException('Email profile name must be non-empty.');
        }
        $profiles = $this->config->get($collectionKey, []);
        if (!is_array($profiles) || !isset($profiles[$name]) || !is_array($profiles[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Email profile "%s" is not configured under %s.',
                $name,
                $collectionKey,
            ));
        }

        return ValueNormalizer::associativeArray($profiles[$name]);
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Notification profile key "%s" must be non-empty.', $key));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function resolveSpoolPaths(array $config): array
    {
        foreach (['directory', 'processingDirectory', 'moveAfterRead', 'failedDirectory'] as $key) {
            $path = $this->nullableString($config[$key] ?? null);
            if ($path !== null) {
                $config[$key] = $this->absolutePath($path);
            }
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function section(string $key): array
    {
        return ValueNormalizer::associativeArray($this->config->get($key, []));
    }

    /** @param array<string, mixed> $config */
    private function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /** @return array<string, mixed> */
    private function transportConfig(string $transport): array
    {
        $config = $this->config->get('notifications.auth.transports.' . $transport, []);
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf(
                'Notification transport "%s" must be configured as an array.',
                $transport,
            ));
        }

        return ValueNormalizer::associativeArray($config);
    }
}
