<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Internal\AuthNotificationRegistrar;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use League\Flysystem\FilesystemOperator;

it('registers native filesystem factories directly on the owning provider', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Web,
        ['filesystem'],
    );
    $builder = new ContainerBuilder();
    new FilesystemServiceProvider()->contribute($builder, $context);
    $definitions = $builder->definitionGraph()->definitions();

    foreach ([
        FilesystemOperator::class => 'operator',
        UploadProcessor::class => 'upload',
        DownloadProcessor::class => 'download',
    ] as $id => $method) {
        $definition = $definitions[$id] ?? null;

        expect($definition)->toBeInstanceOf(FactoryDefinition::class)
            ->and($definition->signature()['class'])->toBe(FilesystemServiceProvider::class)
            ->and($definition->signature()['method'])->toBe($method);
    }

    expect(class_exists('Infocyph\\Foundation\\Filesystem\\FilesystemGraphFactory'))->toBeFalse();
});

it('registers native auth-notification factory on the owning registrar', function (): void {
    $config = new ConfigRepository([
        'auth' => ['drivers' => ['notifications' => 'talkingbytes']],
    ]);
    $builder = new ContainerBuilder();
    new AuthNotificationRegistrar($config, $builder)->register(new AuthDriverResolver($config));
    $definition = $builder->definitionGraph()->definitions()[AuthNotifierInterface::class] ?? null;

    expect($definition)->toBeInstanceOf(FactoryDefinition::class)
        ->and($definition->signature()['class'])->toBe(AuthNotificationRegistrar::class)
        ->and($definition->signature()['method'])->toBe('talkingBytes')
        ->and(class_exists('Infocyph\\Foundation\\Auth\\Internal\\AuthNotificationGraphFactory'))->toBeFalse();
});

it('binds the development Webrick router identities on the routing provider', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Web,
        ['routing'],
    );
    $builder = new ContainerBuilder();
    new RoutingServiceProvider()->contribute($builder, $context);
    $definitions = $builder->definitionGraph()->definitions();

    foreach ([Registrar::class => 'registrar', Collection::class => 'routes'] as $id => $method) {
        $definition = $definitions[$id] ?? null;

        expect($definition)->toBeInstanceOf(FactoryDefinition::class)
            ->and($definition->signature()['class'])->toBe(RoutingServiceProvider::class)
            ->and($definition->signature()['method'])->toBe($method);
    }

    expect(class_exists('Infocyph\\Foundation\\Routing\\WebrickRuntimeFactory'))->toBeFalse();
});
