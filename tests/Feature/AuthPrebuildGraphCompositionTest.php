<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
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
    $builder = FoundationGraph::compose($context);

    expect($builder->definitions()->has(\Infocyph\Foundation\Application\Application::class))->toBeFalse();

    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitions()->has(AuthDriverResolver::class))->toBeTrue()
        ->and($builder->definitions()->has(PasswordPolicyInterface::class))->toBeTrue();
});

it('contributes the built-in token secret and passkey lockout services without inspecting partial definitions', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Cli,
        ['auth'],
    );
    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitions()->has(AuthSecretResolver::class))->toBeTrue()
        ->and($builder->definitions()->has(LockoutManager::class))->toBeTrue()
        ->and($builder->definitions()->has(PasskeyManager::class))->toBeTrue();
});

it('registers optional token managers from the selected token driver rather than unfinished definition lookups', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        RuntimeMode::Cli,
        ['auth'],
    );
    $builder = FoundationGraph::compose($context);
    new AuthServiceProvider()->contribute($builder, $context);

    expect($builder->definitions()->has(PasswordResetManager::class))
        ->toBe(class_exists(PurposeToken::class))
        ->and($builder->definitions()->has(TokenAuthManager::class))
        ->toBe(class_exists(PurposeToken::class));
});
