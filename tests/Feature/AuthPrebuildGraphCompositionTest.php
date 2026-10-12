<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
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
