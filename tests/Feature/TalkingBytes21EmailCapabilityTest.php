<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

it('keeps TalkingBytes communication and email graphs cold until their Foundation capabilities are selected', function (): void {
    $base = [
        'app' => [
            'base_path' => sys_get_temp_dir(),
            'load_env' => false,
        ],
        '_config_cache' => false,
    ];

    $communication = Foundation::worker([
        ...$base,
        'app' => [
            ...$base['app'],
            'capabilities' => ['communication'],
        ],
    ]);

    $communicationProviders = $communication->providers()->classes();
    expect($communicationProviders)->toContain(CommunicationServiceProvider::class)
        ->not->toContain(NotificationServiceProvider::class);

    $notifications = Foundation::worker([
        ...$base,
        'app' => [
            ...$base['app'],
            'capabilities' => ['notifications'],
        ],
        'notifications' => [
            'email' => [
                'default_sender' => 'default',
                'senders' => [
                    'default' => ['transport' => 'fake'],
                ],
                'transports' => [
                    'fake' => ['driver' => 'fake'],
                ],
            ],
        ],
    ])->boot();

    $notificationProviders = $notifications->providers()->classes();
    expect($notificationProviders)->toContain(NotificationServiceProvider::class)
        ->not->toContain(CommunicationServiceProvider::class)
        ->and($notifications->has('foundation.notifications'))->toBeTrue()
        ->and($notifications->has('foundation.email'))->toBeTrue();
});


it('statically compiles the production notification graph without TalkingBytes constructor skips', function (): void {
    $root = sys_get_temp_dir() . '/foundation-talkingbytes-21-release-' . bin2hex(random_bytes(6));
    mkdir($root . '/routes', 0775, true);
    mkdir($root . '/bootstrap/cache', 0775, true);
    file_put_contents($root . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/health', static fn(): Response => Response::json(['status' => 'ok']));
PHP);

    $config = [
        'app' => [
            'base_path' => $root,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'notifications' => [
            'email' => [
                'default_sender' => 'auth',
                'senders' => [
                    'auth' => ['transport' => 'log'],
                ],
                'transports' => [
                    'log' => ['driver' => 'log'],
                ],
            ],
        ],
        'router' => [
            'matcher' => 'fused',
            'files' => ['web.php'],
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];

    try {
        $release = (new WebReleaseCompiler())->compile(
            $config,
            $root . '/bootstrap/cache/intermix.php',
            $root . '/bootstrap/cache/router.php',
            $root . '/bootstrap/cache/release.json',
            capabilities: ['notifications'],
        );

        expect($release['intermix']['skipped'] ?? null)->toBe([]);
    } finally {
        foundationTalkingBytes21EmailRemove($root);
    }
});


it('keeps mailbox instances caller-owned and spool receivers execution-scoped', function (): void {
    $root = sys_get_temp_dir() . '/foundation-talkingbytes-21-email-' . bin2hex(random_bytes(6));
    $spool = $root . '/spool';
    $processing = $root . '/processing';
    $processed = $root . '/processed';
    $failed = $root . '/failed';
    foreach ([$spool, $processing, $processed, $failed] as $directory) {
        mkdir($directory, 0775, true);
    }

    try {
        $app = Foundation::worker([
            'app' => [
                'base_path' => $root,
                'load_env' => false,
                'capabilities' => ['notifications'],
            ],
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
                                'directory' => $spool,
                                'processingDirectory' => $processing,
                                'moveAfterRead' => $processed,
                                'failedDirectory' => $failed,
                            ],
                        ],
                    ],
                    'mailboxes' => [
                        'imap' => [
                            'default' => [
                                'host' => 'imap.example.test',
                                'port' => 993,
                                'security' => 'ssl',
                                'username' => 'user@example.test',
                                'password' => 'test-password',
                            ],
                        ],
                        'pop3' => [
                            'default' => [
                                'host' => 'pop3.example.test',
                                'port' => 995,
                                'security' => 'ssl',
                                'username' => 'user@example.test',
                                'password' => 'test-password',
                            ],
                        ],
                    ],
                ],
            ],
        ])->boot();

        $profiles = $app->make(EmailProfiles::class);
        $imapFirst = $profiles->imapMailbox();
        $imapSecond = $profiles->imapMailbox();
        $popFirst = $profiles->pop3Mailbox();
        $popSecond = $profiles->pop3Mailbox();

        expect($imapFirst)->toBeInstanceOf(Mailbox::class)
            ->and($imapSecond)->toBeInstanceOf(Mailbox::class)
            ->and($imapFirst)->not->toBe($imapSecond)
            ->and($popFirst)->toBeInstanceOf(Pop3Mailbox::class)
            ->and($popSecond)->toBeInstanceOf(Pop3Mailbox::class)
            ->and($popFirst)->not->toBe($popSecond);

        $firstReceiver = $app->execution()->run(
            static fn(): SpoolEmailReceiver => $app->make(SpoolEmailReceiver::class),
        );
        $secondReceiver = $app->execution()->run(
            static fn(): SpoolEmailReceiver => $app->make(SpoolEmailReceiver::class),
        );

        expect($firstReceiver)->not->toBe($secondReceiver);
    } finally {
        foundationTalkingBytes21EmailRemove($root);
    }
});

function foundationTalkingBytes21EmailRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
