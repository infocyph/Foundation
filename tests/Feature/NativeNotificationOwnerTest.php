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
