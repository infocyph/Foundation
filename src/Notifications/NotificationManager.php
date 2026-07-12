<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\AbstractContainerManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Dkim\DkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;
use Infocyph\TalkingBytes\Email\Dkim\DnsDkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\Testing\AssertableEmailTransport;
use Infocyph\TalkingBytes\Email\ValueObject\AuthenticationResults;
use Infocyph\TalkingBytes\Email\ValueObject\BounceReport;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

final readonly class NotificationManager extends AbstractContainerManager
{
    public function assertableEmailer(): AssertableEmailTransport
    {
        return $this->emailer()->assertable();
    }

    public function authenticationResultsParser(): AuthenticationResultsParser
    {
        return $this->typedService(
            AuthenticationResultsParser::class,
            'Notification authentication results parser must resolve to AuthenticationResultsParser.',
        );
    }

    public function authNotifier(): AuthNotifierInterface
    {
        return $this->typedService(
            AuthNotifierInterface::class,
            'Notification auth notifier must resolve to AuthNotifierInterface.',
        );
    }

    public function bounceParser(): BounceParser
    {
        return $this->typedService(
            BounceParser::class,
            'Notification bounce parser must resolve to BounceParser.',
        );
    }

    public function dkimVerifier(?DkimPublicKeyResolver $resolver = null): DkimVerifier
    {
        return new DkimVerifier($resolver ?? new DnsDkimPublicKeyResolver());
    }

    public function emailer(): Emailer
    {
        return $this->typedService(
            Emailer::class,
            'Foundation notification emailer must resolve to TalkingBytes Emailer.',
        );
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public function emailEvents(?callable $listener): void
    {
        Email::events($listener);
    }

    public function imapMailbox(string $profile = 'default'): Mailbox
    {
        return $this->mailboxFactory()->usingImap(ImapConfig::fromArray(
            $this->profileConfig('email.mailboxes.imap', $profile),
        ));
    }

    public function mailboxFactory(): EmailMailboxFactory
    {
        return $this->typedService(
            EmailMailboxFactory::class,
            'Notification mailbox factory must resolve to EmailMailboxFactory.',
        );
    }

    public function parseAuthenticationResults(?string $headerValue): AuthenticationResults
    {
        return $this->authenticationResultsParser()->parse($headerValue);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function parseBounce(ParsedEmail|string $email, array $metadata = []): ?BounceReport
    {
        $parsed = is_string($email)
            ? $this->parseRawEmail($email, $metadata)
            : $email;

        return $this->bounceParser()->parse($parsed);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function parseRawEmail(string $rawEmail, array $metadata = []): ParsedEmail
    {
        return $this->rawEmailParser()->parse($rawEmail, $metadata);
    }

    public function pop3Mailbox(string $profile = 'default'): Pop3Mailbox
    {
        return $this->mailboxFactory()->usingPop3(Pop3Config::fromArray(
            $this->profileConfig('email.mailboxes.pop3', $profile),
        ));
    }

    public function rawEmailParser(): RawEmailParser
    {
        return $this->typedService(
            RawEmailParser::class,
            'Notification raw email parser must resolve to RawEmailParser.',
        );
    }

    public function receiverFactory(): EmailReceiverFactory
    {
        return $this->typedService(
            EmailReceiverFactory::class,
            'Notification receiver factory must resolve to EmailReceiverFactory.',
        );
    }

    public function senderFactory(): EmailSenderFactory
    {
        return $this->typedService(
            EmailSenderFactory::class,
            'Notification sender factory must resolve to EmailSenderFactory.',
        );
    }

    public function spoolReceiver(string $profile = 'default'): SpoolEmailReceiver
    {
        $config = $this->normalizeSpoolConfig(
            $this->profileConfig('email.receivers.spool', $profile),
        );

        return $this->receiverFactory()->usingSpool(
            SpoolConfig::fromArray($config),
            $this->rawEmailParser(),
            $this->boolValue($config, 'deleteAfterRead', false),
            $this->nullableStringValue($config, 'moveAfterRead'),
            $this->nullableStringValue($config, 'failedDirectory'),
        );
    }

    protected function configSection(): string
    {
        return 'notifications';
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
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

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeSpoolConfig(array $config): array
    {
        foreach (['directory', 'processingDirectory', 'moveAfterRead', 'failedDirectory'] as $key) {
            $resolved = $this->resolvedPath($config[$key] ?? null);
            if ($resolved !== null) {
                $config[$key] = $resolved;
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nullableStringValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function paths(): PathManager
    {
        return $this->typedService(
            PathManager::class,
            'Notification paths manager must resolve to PathManager.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(string $key, string $profile): array
    {
        return ValueNormalizer::associativeArray(
            $this->config($key . '.' . $profile, []),
        );
    }

    private function resolvedPath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        if ($this->absolute($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return $this->paths()->base($path);
    }
}
