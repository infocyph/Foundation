<?php

declare(strict_types=1);

use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;
use Infocyph\Foundation\Module\ModuleFeatureResolver;

function f4FeatureFixture(): array
{
    return [
        'root_known' => true,
        'root_requirements' => [],
        'installed' => [],
        'enabled' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'hash', 'mbstring', 'ctype'],
        'published_configs' => [],
    ];
}

it('normalizes all nine features into canonical deterministic group order', function (): void {
    $resolver = new ModuleFeatureResolver();
    $catalog = new ModuleArchitectureCatalog();
    $all = [];
    foreach ($catalog->all() as $module => $definition) {
        foreach (array_keys($definition['features']) as $feature) {
            $all[] = $module . '.' . $feature;
        }
    }

    expect($resolver->normalize(array_reverse([...$all, 'security.mfa'])))->toBe($all)
        ->and(fn() => $resolver->normalize(['runtime']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $resolver->normalize(['core.cache']))->toThrow(InvalidArgumentException::class);
});

it('creates a versioned pure installation plan without activating siblings or core', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $snapshot = serialize($context);
    $plan = $planner->plan('install', ['security.passkey'], $context);

    expect($plan['schema_version'])->toBe(2)
        ->and($plan['requested'])->toBe(['security.passkey'])
        ->and($plan['required'])->toBe(['security.passkey'])
        ->and($plan['effects']['require'])->toBe(['infocyph/otp' => '^7.0', 'web-auth/webauthn-lib' => '^5.3.9'])
        ->and($plan['effects']['activate'])->toBe([])
        ->and($plan['applicable'])->toBeTrue()
        ->and(serialize($context))->toBe($snapshot)
        ->and($planner->plan('install', ['security.passkey'], $context))->toBe($plan);
});

it('preserves transitive package ownership and refuses unknown root ownership', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['installed']['infocyph/pathwise'] = '4.2.1';

    $plan = $planner->plan('install', ['data.filesystem'], $context);
    expect($plan['states']['data.filesystem']['packages']['infocyph/pathwise']['transitive'])->toBeTrue()
        ->and($plan['effects']['require'])->toBe(['infocyph/pathwise' => '^4.2'])
        ->and($plan['warnings'])->not->toBeEmpty();

    $context['root_known'] = false;
    $unknown = $planner->plan('install', ['data.filesystem'], $context);
    expect($unknown['applicable'])->toBeFalse()
        ->and(implode(' ', $unknown['blockers']))->toContain('Root Composer ownership is unknown');
});

it('rejects incompatible root constraints and installed specialist versions', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['root_requirements']['infocyph/dblayer'] = '^5.0';
    $context['installed']['infocyph/dblayer'] = '5.1.0';

    $plan = $planner->plan('install', ['data.database'], $context);
    expect($plan['applicable'])->toBeFalse()
        ->and(count($plan['blockers']))->toBeGreaterThanOrEqual(2);

    $context['root_requirements']['infocyph/dblayer'] = '^6.1';
    $context['installed']['infocyph/dblayer'] = '6.1.0';
    expect($planner->plan('install', ['data.database'], $context)['applicable'])->toBeTrue();
});

it('evaluates real conditional cross-module dependencies without enabling sibling features', function (): void {
    $planner = new ModuleFeaturePlanner();
    $ctx = f4FeatureFixture();
    $ctx['config'] = ['messaging' => ['durable' => ['enabled' => true]]];

    $plan = $planner->plan('install', ['execution.messaging'], $ctx);
    expect($plan['required'])->toBe(['data.database', 'execution.messaging'])
        ->and($plan['effects']['require'])->toBe([
            'infocyph/dblayer' => '^6.1',
            'infocyph/omnibus' => '^3.0',
        ])
        ->and($plan['effects']['activate'])->toBe([]);

    $ctx['config']['messaging']['durable']['enabled'] = false;
    expect($planner->plan('install', ['execution.messaging'], $ctx)['required'])->toBe(['execution.messaging']);
});

it('requires native packages and platform before enabling an explicitly allowed feature', function (): void {
    $planner = new ModuleFeaturePlanner();
    $ctx = f4FeatureFixture();
    $ctx['allowlist'] = [];
    $denied = $planner->plan('enable', ['data.database'], $ctx);
    expect($denied['applicable'])->toBeFalse()
        ->and(implode(' ', $denied['blockers']))->toContain('Production capability policy');

    $ctx['allowlist'] = ['data.database'];
    $ctx['installed']['infocyph/dblayer'] = '6.1.1';
    $ctx['root_requirements']['infocyph/dblayer'] = '^6.1';
    expect($planner->plan('enable', ['data.database'], $ctx)['applicable'])->toBeTrue();

    $ctx['extensions'] = [];
    expect($planner->plan('enable', ['data.database'], $ctx)['applicable'])->toBeFalse();
});

it('checks enabled dependents and shared OTP ownership before removal', function (): void {
    $planner = new ModuleFeaturePlanner();
    $ctx = f4FeatureFixture();
    $ctx['root_requirements']['infocyph/otp'] = '^7.0';
    $ctx['installed']['infocyph/otp'] = '7.0.0';
    $ctx['enabled'] = ['security.passkey'];

    $plan = $planner->plan('remove', ['security.mfa'], $ctx);
    expect($plan['effects']['remove'])->toBe([])
        ->and($plan['applicable'])->toBeTrue();

    $ctx['enabled'] = ['security.mfa'];
    expect($planner->plan('remove', ['security.mfa'], $ctx)['applicable'])->toBeFalse();
});

it('preserves root content and emits stable repeatable effects for repair', function (): void {
    $planner = new ModuleFeaturePlanner();
    $ctx = f4FeatureFixture();
    $plan = $planner->plan('repair', ['execution.messaging'], $ctx);

    expect($plan['effects']['publish'])->toBe(['messaging.php'])
        ->and($plan['effects']['schemas'])->toBe(['messaging'])
        ->and($plan['effects']['require'])->toBe(['infocyph/omnibus' => '^3.0'])
        ->and($plan['effects']['remove'])->toBe([])
        ->and($plan['effects']['activate'])->toBe([]);
});

it('plans each of the nine independent features without pulling unrelated sibling packages', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();

    foreach ($catalog->all() as $module => $definition) {
        foreach ($definition['features'] as $feature => $metadata) {
            $id = $module . '.' . $feature;
            $plan = $planner->plan('install', [$id], $context);
            expect($plan['requested'])->toBe([$id])
                ->and($plan['effects']['activate'])->toBe([])
                ->and($plan['applicable'])->toBeTrue()
                ->and(array_keys($plan['effects']['require']))->toBe(array_keys($metadata['packages']));
        }
    }
});

it('preserves disabled but explicitly selected feature ownership when removing shared packages', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['root_requirements']['infocyph/otp'] = '^7.0';
    $context['installed']['infocyph/otp'] = '7.0.0';
    $context['selected_features'] = ['security.passkey'];

    expect($planner->plan('remove', ['security.mfa'], $context)['effects']['remove'])->toBe([]);
});

it('fails closed on unknown installed versions and unavailable required extensions', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['installed']['infocyph/dblayer'] = 'unstable-opaque';
    expect($planner->plan('install', ['data.database'], $context)['applicable'])->toBeFalse();

    $context['installed'] = [];
    $context['extensions'] = [];
    expect($planner->plan('install', ['data.database'], $context)['applicable'])->toBeFalse();
});

it('denies malformed operations and feature-less module installs', function (): void {
    $planner = new ModuleFeaturePlanner();
    $ctx = f4FeatureFixture();

    expect(fn() => $planner->plan('install', [], $ctx))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $planner->plan('deploy', ['data.database'], $ctx))->toThrow(InvalidArgumentException::class);
});

it('validates conditional prerequisite extensions and includes prerequisite config', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];
    $context['extensions'] = array_values(array_diff($context['extensions'], ['pdo']));

    $plan = $planner->plan('install', ['execution.messaging'], $context);
    expect($plan['required'])->toBe(['data.database', 'execution.messaging'])
        ->and($plan['effects']['publish'])->toBe(['database.php', 'messaging.php'])
        ->and($plan['applicable'])->toBeFalse()
        ->and(implode(' ', $plan['blockers']))->toContain('Extension pdo is required for data.database.');
});

it('checks conditional prerequisite readiness when enabling durable messaging', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];
    $context['enabled'] = ['data.database'];
    $context['root_requirements']['infocyph/omnibus'] = '^3.0';
    $context['installed']['infocyph/omnibus'] = '3.0.0';

    $missing = $planner->plan('enable', ['execution.messaging'], $context);
    expect($missing['applicable'])->toBeFalse()
        ->and(implode(' ', $missing['blockers']))->toContain('infocyph/dblayer');

    $context['root_requirements']['infocyph/dblayer'] = '^6.1';
    $context['installed']['infocyph/dblayer'] = '6.1.0';
    expect($planner->plan('enable', ['execution.messaging'], $context)['applicable'])->toBeTrue();
});

it('allows safe disable and remove when a feature has an incompatible package', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['root_requirements']['infocyph/dblayer'] = '^5.0';
    $context['installed']['infocyph/dblayer'] = '5.1.0';
    $context['enabled'] = ['data.database'];

    $disable = $planner->plan('disable', ['data.database'], $context);
    expect($disable['applicable'])->toBeTrue()
        ->and($disable['effects']['activate'])->toBe(['data.database' => false]);

    $context['enabled'] = [];
    $remove = $planner->plan('remove', ['data.database'], $context);
    expect($remove['applicable'])->toBeTrue()
        ->and($remove['effects']['remove'])->toBe(['infocyph/dblayer']);
});

it('removes jointly selected shared packages only once', function (): void {
    $planner = new ModuleFeaturePlanner();
    $context = f4FeatureFixture();
    $context['selected_features'] = ['security.mfa', 'security.passkey'];
    $context['root_requirements'] = [
        'infocyph/otp' => '^7.0',
        'web-auth/webauthn-lib' => '^5.3.9',
    ];
    $context['installed'] = [
        'infocyph/otp' => '7.0.0',
        'web-auth/webauthn-lib' => '5.3.9',
    ];

    $plan = $planner->plan('remove', ['security.passkey', 'security.mfa'], $context);
    expect($plan['applicable'])->toBeTrue()
        ->and($plan['effects']['remove'])->toBe(['infocyph/otp', 'web-auth/webauthn-lib']);
});
