<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Epicrypt\Password\PasswordHashOptions;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

final class SecurityServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (!class_exists(AeadCipher::class)) {
            throw new \LogicException(
                'Foundation security services require infocyph/epicrypt; run "php infbyte module:install crypto".',
            );
        }

        $security = is_array($context->config['security'] ?? null) ? $context->config['security'] : [];
        $password = is_array($security['password'] ?? null) ? $security['password'] : [];

        if (!$builder->definitions()->has(PasswordHashOptions::class)) {
            $builder->singleton(
                PasswordHashOptions::class,
                FactoryDefinition::staticFactory(
                    SecurityGraphFactory::class,
                    'passwordOptions',
                    [$password],
                ),
            );
        }
        if (!$builder->definitions()->has(PasswordHasher::class)) {
            $builder->singleton(
                PasswordHasher::class,
                FactoryDefinition::construct(PasswordHasher::class, [
                    new ServiceReference(PasswordHashOptions::class),
                ]),
            );
        }
        if (!$builder->definitions()->has(AeadCipher::class)) {
            $builder->singleton(AeadCipher::class, FactoryDefinition::construct(AeadCipher::class));
        }

        $builder->alias('foundation.security', AeadCipher::class);
        $builder->alias('foundation.crypto', AeadCipher::class);
    }
}
