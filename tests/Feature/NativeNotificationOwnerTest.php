<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

it('exports native TalkingBytes notifications without another Foundation graph factory class', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Web,
        ['notifications'],
    );
    $builder = new ContainerBuilder();
    new NotificationServiceProvider()->contribute($builder, $context);
    $definitions = $builder->definitionGraph()->definitions();

    foreach ([
        EmailLimits::class => 'emailLimits',
        RawEmailParser::class => 'rawEmailParser',
        Emailer::class => 'emailer',
        SpoolEmailReceiver::class => 'spoolReceiver',
    ] as $id => $method) {
        $definition = $definitions[$id] ?? null;

        expect($definition)->toBeInstanceOf(FactoryDefinition::class)
            ->and($definition->signature()['class'])->toBe(NotificationServiceProvider::class)
            ->and($definition->signature()['method'])->toBe($method);
    }

    expect(class_exists('Infocyph\\Foundation\\Notifications\\NotificationGraphFactory'))
        ->toBeFalse();
});

it('compiles native email defaults and forwards a passed host request without retaining it in factories', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-native-email-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $configuration = [
        'app' => ['base_path' => $directory, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'notifications' => ['email' => [
            'default_sender' => 'test',
            'senders' => ['test' => ['transport' => 'fake']],
            'transports' => ['fake' => ['driver' => 'fake']],
        ]],
    ];
    $capabilities = ['notifications', 'runwire'];
    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    $request = \Infocyph\Runwire\RequestContext::create($runtime);
    $message = \Infocyph\TalkingBytes\Email\EmailMessage::new()
        ->from('sender@example.test')->to('receiver@example.test')->subject('Native context')->text('test');

    try {
        $artifact = $directory . '/runtime.php';
        $report = new \Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler()->compile(
            $configuration, RuntimeMode::Worker, $artifact, $capabilities,
        );
        expect($report['skipped'])->toBe([]);
        $loaded = \Infocyph\Foundation\Runtime\GeneratedRuntime::loadPrevalidated(
            $configuration, RuntimeMode::Worker, $artifact, $report['metadata_sha256'], $report['digest'], $capabilities,
        );
        $app = $loaded->application;
        $app->execution()->run(static function () use ($app, $request, $message): void {
            $sender = $app->make(\Infocyph\Foundation\Notifications\EmailProfiles::class)->sender();
            expect($sender->send($message)->successful)->toBeTrue();
            $request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
            $cancelled = $sender->send($message);
            expect($cancelled->successful)->toBeFalse()
                ->and($cancelled->metadata['cancelled'] ?? false)->toBeTrue()
                ->and($cancelled->metadata['attempts'] ?? -1)->toBe(0);
        }, [\Infocyph\Runwire\RuntimeContext::class => $runtime, \Infocyph\Runwire\RequestContext::class => $request]);
        $app->execution()->run(static function () use ($app, $message): void {
            $sender = $app->make(\Infocyph\Foundation\Notifications\EmailProfiles::class)->sender();
            $sender->assertable()->assertNothingSent();
            expect($sender->send($message)->successful)->toBeTrue();
        });
        expect($request->completed())->toBeFalse()
            ->and(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBeNull()
            ->and(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::runtime())->toBeNull();
    } finally {
        $request->complete();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
});
