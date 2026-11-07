<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Internal\AuthRuntimeRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthStoreRegistrar;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

it('keeps the native authentication and audit-service recipes on their owning registrars', function (): void {
    $config = new ConfigRepository([
        'messaging' => ['forward_auth_events' => true],
    ]);

    $runtimeBuilder = new ContainerBuilder();
    new AuthRuntimeRegistrar($config, $runtimeBuilder)->register();
    $runtime = $runtimeBuilder->definitionGraph()->definitions()[AuthManager::class] ?? null;

    expect($runtime)->toBeInstanceOf(FactoryDefinition::class)
        ->and($runtime->signature()['class'])->toBe(AuthRuntimeRegistrar::class)
        ->and($runtime->signature()['method'])->toBe('manager');

    $storeBuilder = new ContainerBuilder();
    new AuthStoreRegistrar($config, $storeBuilder)->register(new AuthDriverResolver($config));
    $audit = $storeBuilder->definitionGraph()->definitions()[AuditEventStoreInterface::class] ?? null;

    expect($audit)->toBeInstanceOf(FactoryDefinition::class)
        ->and($audit->signature()['class'])->toBe(AuthStoreRegistrar::class)
        ->and($audit->signature()['method'])->toBe('forwardingAuditStore')
        ->and(class_exists('Infocyph\\Foundation\\Auth\\Internal\\AuthRuntimeGraphFactory'))->toBeFalse()
        ->and(class_exists('Infocyph\\Foundation\\Auth\\Internal\\AuthStoreGraphFactory'))->toBeFalse();
});
