<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\AuthOtpServiceProvider;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Authorization\Gate\Gate;
use Infocyph\Foundation\Auth\Authorization\Policy\PolicyInterface;
use Infocyph\Foundation\Auth\Authorization\Policy\PolicyResolverInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;

it('contributes authentication services before any Application runtime has been registered', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => ['env' => 'testing'],
            'auth' => ['password_policy' => ['min_length' => 14, 'max_length' => 18]],
        ]),
        RuntimeMode::Cli,
        ['auth'],
    );
    expect(FoundationGraph::compose($context)->definitionGraph()->hasDefinition(Application::class))->toBeFalse();

    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitionGraph()->hasDefinition(AuthDriverResolver::class))->toBeTrue()
        ->and($builder->definitionGraph()->hasDefinition(PasswordPolicyInterface::class))->toBeTrue();
});

it('contributes the built-in token secret and passkey lockout services without inspecting partial definitions', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Cli,
        ['auth'],
    );
    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitionGraph()->hasDefinition(AuthSecretResolver::class))->toBeTrue()
        ->and($builder->definitionGraph()->hasDefinition(LockoutManager::class))->toBeTrue()
        ->and($builder->definitionGraph()->hasDefinition(PasskeyManager::class))->toBeTrue();
});

it('registers optional token managers from the selected token driver rather than unfinished definition lookups', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Cli,
        ['auth'],
    );
    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitionGraph()->hasDefinition(PasswordResetManager::class))
        ->toBe(class_exists(PurposeToken::class))
        ->and($builder->definitionGraph()->hasDefinition(TokenAuthManager::class))
        ->toBe(class_exists(PurposeToken::class));
});

it('selects exactly one native OTP registration owner when authentication chooses OTP', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => ['env' => 'testing'],
            'auth' => ['drivers' => ['mfa' => 'otp']],
        ]),
        RuntimeMode::Cli,
        ['auth', 'otp'],
    );
    $otpOnly = FoundationGraph::compose($context);
    new AuthOtpServiceProvider()->contribute($otpOnly, $context);
    expect($otpOnly->definitionGraph()->hasDefinition(OtpProvisioningService::class))->toBeFalse();

    $builder = FoundationGraph::compose($context);
    new AuthOtpServiceProvider()->contribute($builder, $context);
    new AuthServiceProvider()->contribute($builder, $context);
    expect($builder->definitionGraph()->hasDefinition(OtpProvisioningService::class))->toBeTrue();
});

it('resolves optional authorization policies against the completed runtime graph', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Cli,
        ['auth'],
    );
    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    $resolver = new class implements PolicyResolverInterface {
        public int $calls = 0;

        public function resolve(mixed $resource): ?PolicyInterface
        {
            expect($resource)->toBeInstanceOf(stdClass::class);
            $this->calls++;

            return null;
        }
    };
    $builder->value(PolicyResolverInterface::class, $resolver);
    $container = $builder->build();
    $gate = $container->get(Gate::class);

    expect($gate)->toBeInstanceOf(Gate::class);
    $gate->can(new Principal('policy-test', accountId: 'policy-test'), 'view', new stdClass());
    expect($resolver->calls)->toBe(1);
});
