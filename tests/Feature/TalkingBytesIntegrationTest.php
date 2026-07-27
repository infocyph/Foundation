<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\BounceType;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;

beforeEach(function (): void {
    if (!class_exists(EmailMessage::class)) {
        $this->markTestSkipped('Install the communication module to run TalkingBytes integration tests.');
    }
});

it('exposes the broader TalkingBytes email stack through foundation notifications', function (): void {
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
            'auth' => [
                'transport' => 'fake',
            ],
            'email' => [
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
                            'host' => 'imap.example.test',
                            'port' => 993,
                            'security' => 'ssl',
                            'username' => 'demo@example.test',
                            'password' => 'secret',
                            'timeoutSeconds' => 10,
                            'defaultFolder' => 'INBOX',
                        ],
                    ],
                    'pop3' => [
                        'default' => [
                            'host' => 'pop3.example.test',
                            'port' => 995,
                            'security' => 'ssl',
                            'username' => 'demo@example.test',
                            'password' => 'secret',
                            'timeoutSeconds' => 10,
                        ],
                    ],
                ],
            ],
        ],
    ])->boot();

    $notifications = $app->notifications();
    $events = [];
    $notifications->emailEvents(static function (string $event, array $payload) use (&$events): void {
        $events[] = [$event, $payload];
    });

    try {
        $notifications->emailer()->send(
            EmailMessage::new()
                ->from('sender@example.test')
                ->to('user@example.test')
                ->subject('Framework Mail')
                ->text('Hello from Foundation')
        );

        $notifications->assertableEmailer()->assertSentCount(1);
        $notifications->assertableEmailer()->assertSentTo('user@example.test');
        $notifications->assertableEmailer()->assertSentSubject('Framework Mail');

        $parsed = $notifications->parseRawEmail(str_replace("\n", "\r\n", $rawInbound), ['source' => 'feature-test']);

        expect($parsed->subject)->toBe('Inbound hello');
        expect($parsed->fromEmail())->toBe('sender@example.test');

        $authResults = $notifications->parseAuthenticationResults($parsed->header('Authentication-Results'));

        expect($authResults->passedDkim())->toBeTrue();
        expect($authResults->passedSpf())->toBeTrue();

        $bounce = $notifications->parseBounce(str_replace("\n", "\r\n", $rawBounce), ['source' => 'feature-bounce']);

        expect($bounce)->not->toBeNull();
        expect($bounce?->type)->toBe(BounceType::UserUnknown);
        expect($bounce?->recipient)->toBe('user@example.test');

        $receiver = $notifications->spoolReceiver();
        $peeked = $receiver->peek();
        $received = $receiver->receive();

        expect($peeked?->subject)->toBe('Inbound hello');
        expect($received?->subject)->toBe('Inbound hello');
        expect($received?->metadata['source'] ?? null)->toBe('spool');
        expect(glob($processedDirectory . '/*.eml'))->not->toBeFalse()->not->toBeEmpty();

        expect($notifications->mailboxFactory())->toBeObject();
        expect($notifications->receiverFactory())->toBeObject();
        expect($notifications->senderFactory())->toBeObject();
        expect($notifications->imapMailbox())->toBeInstanceOf(Mailbox::class);
        expect($notifications->pop3Mailbox())->toBeInstanceOf(Pop3Mailbox::class);
        expect(array_column($events, 0))->toContain('email.send.start', 'email.send.finish', 'email.receive.start', 'email.receive.finish', 'bounce.detected');
    } finally {
        $notifications->emailEvents(null);
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

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}
