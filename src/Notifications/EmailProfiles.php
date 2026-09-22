<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

/**
 * Maps Foundation application email profiles to native TalkingBytes objects.
 *
 * TalkingBytes owns transport/decorator composition, parsing, receiving and
 * mailbox behavior. Foundation owns profile selection, secrets and path policy.
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
        $profile = $this->stringConfig(
            'notifications.auth.sender',
            $this->defaultSender(),
        );

        return $this->sender($profile);
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

    public function sender(?string $profile = null): Emailer
    {
        $config = $this->senderProfile($profile);
        $primary = $this->requiredString($config, 'transport');
        $fallback = ValueNormalizer::associativeArray($config['fallback'] ?? []);
        $fallbacks = [];

        foreach (ValueNormalizer::stringList($fallback['transports'] ?? []) as $transport) {
            if ($transport === $primary) {
                continue;
            }

            $fallbacks[] = $this->resolvedTransport($transport);
        }

        return $this->senders->fromResolvedConfig([
            'transport' => $this->resolvedTransport($primary),
            'fallbacks' => $fallbacks,
            'retry' => ValueNormalizer::associativeArray($config['retry'] ?? []),
            'rate_limit' => ValueNormalizer::associativeArray($config['rate_limit'] ?? []),
            'dkim' => $this->resolvedDkim(ValueNormalizer::associativeArray($config['dkim'] ?? [])),
        ]);
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

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function absolutePath(string $path): string
    {
        return $this->absolute($path) ? rtrim($path, DIRECTORY_SEPARATOR) : $this->paths->base($path);
    }

    private function defaultSender(): string
    {
        return $this->stringConfig('notifications.email.default_sender', 'default');
    }

    /** @param array<string, mixed> $config */
    private function logDirectory(array $config): string
    {
        $configured = $config['directory'] ?? null;
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
            throw new \InvalidArgumentException(sprintf('Email profile key "%s" must be non-empty.', $key));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $config */
    private function resolvedDkim(array $config): array
    {
        $privateKeyPath = $this->nullableString($config['private_key_path'] ?? null);
        if ($privateKeyPath !== null) {
            $config['private_key_path'] = $this->absolutePath($privateKeyPath);
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function resolvedTransport(string $transport): array
    {
        $config = $this->transportConfig($transport);
        $driver = $this->string($config, 'driver', $transport);
        $config['driver'] = $driver;

        return match ($driver) {
            'log' => [
                ...$config,
                'directory' => $this->logDirectory($config),
            ],
            'spool' => $this->resolveSpoolPaths($config),
            'fake', 'mail', 'null', 'sendmail', 'smtp' => $config,
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported email transport driver "%s" for profile "%s".',
                $driver,
                $transport,
            )),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
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
    private function senderProfile(?string $profile): array
    {
        $name = is_string($profile) && trim($profile) !== '' ? trim($profile) : $this->defaultSender();

        return $this->profile('notifications.email.senders', $name);
    }

    /** @param array<string, mixed> $config */
    private function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /** @return array<string, mixed> */
    private function transportConfig(string $transport): array
    {
        return $this->profile('notifications.email.transports', $transport);
    }
}
