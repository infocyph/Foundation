<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(AeadCipher::class)) {
            throw new \LogicException(
                'Foundation security services require infocyph/epicrypt; run "php infbyte module:install crypto".',
            );
        }

        $container = $app->container();

        $this->bindFactory($container, SecurityManager::class, fn() => new SecurityManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.security', fn() => $container->get(SecurityManager::class), LifetimeEnum::Singleton);
    }
}
