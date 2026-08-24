<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Enum\BounceType;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

beforeEach(function (): void {
    if (!class_exists(EmailMessage::class)) {
        $this->markTestSkipped('Install the communication module to run TalkingBytes integration tests.');
    }
});

it('exposes the broader TalkingBytes email stack through thin Foundation profiles', function (): void {
    $root = dirname(__DIR__, 2);
    $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . '/foundation-talkingbytes-' . bin2hex(random_bytes(5));
    $spoolDirectory = $temporaryRoot . '/inbound';
    $processingDirectory = $temporaryRoot . '/processing';
    $processedDirectory = $temporaryRoot . '/processed';
    $failedDirectory = $temporaryRoot . '/failed';
    $rawInbound = <<<MAIL
From: Sender <sender@example.test>
To: User <user@example.test>
Subject: Inbound hello
Message-ID: <message@example.test>
Authentication-Results: mx.example.test; dkim=pass header.d=example.test; spf=pass smtp.mailfrom=example.test
Date: Tue, 01 Jul 2026 12:00:00 +0000
Content-Type: text/plain; charset=UTF-8

Hello inbound
MAIL;
    $rawBounce = <<<MAIL
From: MAILER-DAEMON@example.test
To: sender@example.test
Subject: Mail delivery failed
Content-Type: text/plain; charset=UTF-8

550 5.1.1 user unknown user@example.test
MAIL;

    foreach ([$spoolDirectory, $processingDirectory, $processedDirectory, $failedDirectory] as $directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
    file_put_contents($spoolDirectory . '/message-001.eml', str_replace("\n", "\r\n", $rawInbound));

    $app = Foundation::web([
        'base_path' => $root,
        '_config_cache' => false,
        'notifications' => [
            'email' => [
                'default_sender' => 'default',
                'senders' => [
                    'default' => ['transport' => 'fake'],
                ],
                'transports' => [
                    'fake' => ['driver' => 'fake'],
                ],
                'receivers' => [
                    'spool' => [
                        'default' => [
                            'directory' => $spoolDirectory,
                            'processingDirectory' => $processingDirectory,
                            'extension' => 'eml',
                            'lockBeforeRead' => true,
                            'maxMessages' => 10,
                            'moveAfterRead' => $processedDirectory,
                            'failedDirectory' => $failedDirectory,
                        ],
                    ],
                ],
                'mailboxes' => [
                    'imap' => [
                        'default' => [
                            'host' => 'imap.example.test', 'port' => 993, 'security' => 'ssl',
                            'username' => 'demo@example.test', 'password' => 'test-password',
                            'timeoutSeconds' => 10, 'defaultFolder' => 'INBOX',
                        ],
                    ],
                    'pop3' => [
                        'default' => [
                            'host' => 'pop3.example.test', 'port' => 995, 'security' => 'ssl',
                            'username' => 'demo@example.test', 'password' => 'test-password',
                            'timeoutSeconds' => 10,
                        ],
                    ],
                ],
            ],
        ],
    ])->boot();

    $profiles = $app->make(EmailProfiles::class);

    try {
        $emailer = $profiles->sender();
        $emailer->send(
            EmailMessage::new()
                ->from('sender@example.test')
                ->to('user@example.test')
                ->subject('Framework Mail')
                ->text('Hello from Foundation'),
        );
        $emailer->assertable()->assertSentCount(1);
        $emailer->assertable()->assertSentTo('user@example.test');
        $emailer->assertable()->assertSentSubject('Framework Mail');

        $parsed = $app->make(RawEmailParser::class)->parse(
            str_replace("\n", "\r\n", $rawInbound),
            ['source' => 'feature-test'],
        );
        expect($parsed->subject)->toBe('Inbound hello')
            ->and($parsed->fromEmail())->toBe('sender@example.test');

        $authResults = $app->make(AuthenticationResultsParser::class)->parse(
            $parsed->header('Authentication-Results'),
        );
        expect($authResults->passedDkim())->toBeTrue()
            ->and($authResults->passedSpf())->toBeTrue();

        $bounce = $app->make(BounceParser::class)->parse(
            str_replace("\n", "\r\n", $rawBounce),
            ['source' => 'feature-bounce'],
        );
        expect($bounce)->not->toBeNull()
            ->and($bounce?->type)->toBe(BounceType::UserUnknown)
            ->and($bounce?->recipient)->toBe('user@example.test');

        $receiver = $profiles->spoolReceiver();
        $peeked = $receiver->peek();
        $received = $receiver->receive();
        expect($peeked?->subject)->toBe('Inbound hello')
            ->and($received?->subject)->toBe('Inbound hello')
            ->and($received?->metadata['source'] ?? null)->toBe('spool')
            ->and(glob($processedDirectory . '/*.eml'))->not->toBeFalse()->not->toBeEmpty();

        expect($app->make(EmailMailboxFactory::class))->toBeObject()
            ->and($app->make(EmailReceiverFactory::class))->toBeObject()
            ->and($app->make(EmailSenderFactory::class))->toBeObject()
            ->and($profiles->imapMailbox())->toBeInstanceOf(Mailbox::class)
            ->and($profiles->pop3Mailbox())->toBeInstanceOf(Pop3Mailbox::class);
    } finally {
        talkingBytesRemoveDirectory($temporaryRoot);
    }
});

function talkingBytesRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            talkingBytesRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}
