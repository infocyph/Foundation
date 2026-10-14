<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Module\ModuleFeatureCapabilityPolicy;
use Infocyph\Foundation\Application\ServiceRegistry;

it('keeps original capability contracts when module selection is absent', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['capabilities' => ['database', 'filesystem']]]),
        RuntimeMode::Cli,
    );

    expect($context->featureSelections)->toBeNull()
        ->and($context->permitsCapability('database'))->toBeTrue()
        ->and($context->permitsCapability('filesystem'))->toBeTrue()
        ->and($context->permitsCapability('auth'))->toBeFalse();
});

it('limits only specialist features and retains the explicitly selected Core topology', function (): void {
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => ['database', 'filesystem', 'messaging', 'auth', 'cache']],
        'modules' => ['features' => ['data.database' => true]],
    ]), RuntimeMode::Cli);

    expect($context->featureSelections)->toBe(['data.database' => true])
        ->and($context->hasFeature('data.database'))->toBeTrue()
        ->and($context->hasFeature('data.filesystem'))->toBeFalse()
        ->and($context->permitsCapability('database'))->toBeTrue()
        ->and($context->permitsCapability('filesystem'))->toBeFalse()
        ->and($context->permitsCapability('messaging'))->toBeFalse()
        ->and($context->permitsCapability('auth'))->toBeTrue()
        ->and($context->permitsCapability('cache'))->toBeTrue();
});

it('never allows selected module features to override the production capability allowlist', function (): void {
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => []],
        'modules' => ['features' => ['data.database' => true, 'execution.runtime' => true]],
    ]), RuntimeMode::Worker);

    expect($context->hasFeature('execution.runtime'))->toBeTrue()
        ->and($context->hasCapability('runwire'))->toBeFalse()
        ->and($context->permitsCapability('database'))->toBeFalse()
        ->and($context->permitsCapability('runwire'))->toBeFalse()
        ->and($context->permitsCapability('auth'))->toBeFalse();
});

it('refuses malformed, bare, unknown or non-boolean module feature overrides before building', function (): void {
    foreach ([
        ['data.database'],
        ['data.database' => 'true'],
        ['database' => true],
        ['core.auth' => true],
        ['execution.unknown' => true],
    ] as $features) {
        expect(fn() => FoundationBuildContext::fromConfig(new ConfigRepository([
            'modules' => ['features' => $features],
        ]), RuntimeMode::Cli))->toThrow(Throwable::class);
    }
});

it('creates a deterministic policy for nine independently selectable features across runtime modes', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $all = [];
    foreach ($catalog->all() as $module => $definition) {
        foreach (array_keys($definition['features']) as $feature) {
            $all[] = $module . '.' . $feature;
        }
    }

    foreach ([RuntimeMode::Web, RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $mode) {
        foreach ($all as $selected) {
            $context = FoundationBuildContext::fromConfig(new ConfigRepository([
                'modules' => ['features' => [$selected => true]],
            ]), $mode);

            expect($context->hasFeature($selected))->toBeTrue();
            foreach ($all as $other) {
                if ($other !== $selected) {
                    expect($context->hasFeature($other))->toBeFalse();
                }
            }
        }
    }
});

it('contributes only selected optional native providers before the InterMix graph is built', function (): void {
    $config = new ConfigRepository([
        'app' => ['capabilities' => ['database', 'filesystem', 'messaging']],
        'modules' => ['features' => ['data.database' => true]],
    ]);
    $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli);
    $builder = FoundationGraph::compose($context);
    $providers = new ServiceRegistry();
    new Bootstrapper()->compose($builder, $context, $providers);
    $names = $providers->classes();

    expect($names)->toContain(DatabaseServiceProvider::class)
        ->not->toContain(FilesystemServiceProvider::class, MessagingServiceProvider::class)
        ->and($builder->definitionGraph()->hasDefinition(\Infocyph\DBLayer\Connection\Connection::class))->toBeBool();
});

it('never silently activates OTP MFA when only passkeys are selected', function (): void {
    $config = new ConfigRepository([
        'app' => ['capabilities' => ['auth']],
        'auth' => ['drivers' => ['mfa' => 'otp']],
        'modules' => ['features' => ['security.passkey' => true]],
    ]);
    $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli);

    expect(fn() => new \Infocyph\Foundation\Auth\AuthServiceProvider()->contribute(
        FoundationGraph::compose($context),
        $context,
    ))->toThrow(LogicException::class, 'security.mfa');
});

it('never silently activates WebAuthn when only MFA is selected', function (): void {
    $config = new ConfigRepository([
        'app' => ['capabilities' => ['auth']],
        'auth' => ['drivers' => ['passkey' => 'webauthn']],
        'modules' => ['features' => ['security.mfa' => true]],
    ]);
    $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli);

    expect(fn() => new \Infocyph\Foundation\Auth\AuthServiceProvider()->contribute(
        FoundationGraph::compose($context),
        $context,
    ))->toThrow(LogicException::class, 'security.passkey');
});
