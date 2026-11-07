<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Foundation\Security\SecurityServiceProvider;
use Infocyph\Foundation\Validation\ValidationServiceProvider;

/**
 * @param array<string,bool> $features
 * @param array<string,mixed> $auth
 * @return array{registry:ServiceRegistry,builder:\Infocyph\InterMix\DI\ContainerBuilder}
 */
function moduleB5SecurityGraph(array $features, array $auth = []): array
{
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => [
            'env' => 'testing',
            'capabilities' => ['auth', 'cache', 'security', 'validation', 'database', 'filesystem', 'messaging'],
        ],
        'auth' => $auth,
        'modules' => ['features' => $features],
    ]), RuntimeMode::Cli);

    $builder = FoundationGraph::compose($context);
    $registry = new ServiceRegistry();
    new Bootstrapper()->compose($builder, $context, $registry);

    return ['registry' => $registry, 'builder' => $builder];
}

it('retains prebuilt Core authentication and caching without optional security or data providers', function (): void {
    $graph = moduleB5SecurityGraph([]);
    $classes = $graph['registry']->classes();

    expect($classes)->toContain(AuthServiceProvider::class, CacheServiceProvider::class)
        ->not->toContain(SecurityServiceProvider::class, ValidationServiceProvider::class)
        ->not->toContain(DatabaseServiceProvider::class, FilesystemServiceProvider::class, MessagingServiceProvider::class)
        ->and($graph['builder']->definitionGraph()->hasDefinition(WebAuthnConfigResolver::class))->toBeFalse()
        ->and($graph['builder']->definitionGraph()->hasDefinition(OtpMfaVerifier::class))->toBeFalse();
});

it('keeps Epicrypt and ReqShield providers distinct and installs each at most once', function (): void {
    $graph = moduleB5SecurityGraph([
        'security.crypto' => true,
        'security.validation' => true,
    ]);
    $classes = $graph['registry']->classes();
    $counts = array_count_values($classes);

    expect($counts[SecurityServiceProvider::class] ?? 0)->toBe(1)
        ->and($counts[ValidationServiceProvider::class] ?? 0)->toBe(1)
        ->and($counts[AuthServiceProvider::class] ?? 0)->toBe(1)
        ->and($classes)->not->toContain(DatabaseServiceProvider::class, MessagingServiceProvider::class);
});

it('selects native OTP MFA and its Epicrypt prerequisite without enabling WebAuthn', function (): void {
    $graph = moduleB5SecurityGraph([
        'security.crypto' => true,
        'security.mfa' => true,
    ], ['drivers' => ['mfa' => 'otp', 'passkey' => 'disabled']]);

    expect($graph['registry']->classes())->toContain(AuthServiceProvider::class, SecurityServiceProvider::class)
        ->and($graph['builder']->definitionGraph()->hasDefinition(OtpMfaVerifier::class))->toBeTrue()
        ->and($graph['builder']->definitionGraph()->hasDefinition(WebAuthnConfigResolver::class))->toBeFalse();
});

it('selects WebAuthn with shared OTP package without enabling OTP MFA or Epicrypt crypto feature', function (): void {
    $graph = moduleB5SecurityGraph([
        'security.passkey' => true,
    ], ['drivers' => ['mfa' => 'simple', 'passkey' => 'webauthn']]);

    expect($graph['registry']->classes())->toContain(AuthServiceProvider::class)
        ->not->toContain(SecurityServiceProvider::class)
        ->and($graph['builder']->definitionGraph()->hasDefinition(WebAuthnConfigResolver::class))->toBeTrue()
        ->and($graph['builder']->definitionGraph()->hasDefinition(OtpMfaVerifier::class))->toBeFalse();
});
