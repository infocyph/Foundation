<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Internal\AuthAuthorizationRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthCacheRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthCoreRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthManagerRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthMfaRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthNotificationRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasskeyRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasswordRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthProductionGuard;
use Infocyph\Foundation\Auth\Internal\AuthRuntimeRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Internal\AuthStoreRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthTokenRegistrar;
use Infocyph\Foundation\Auth\Internal\EpicryptConfigResolver;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();
        $drivers = new AuthDriverResolver($app->config());
        $secrets = new AuthSecretResolver($app);
        $epicrypt = new EpicryptConfigResolver($app);

        new AuthCoreRegistrar($container)->register($drivers);
        new AuthProductionGuard($app)->guard($drivers);
        new AuthStoreRegistrar($app, $container)->register($drivers->storage());
        new AuthCacheRegistrar($app, $container)->register($drivers);
        new AuthPasswordRegistrar($app, $container, $epicrypt)->register($drivers);
        new AuthTokenRegistrar($app, $container, $secrets, $epicrypt)->register($drivers);
        new AuthMfaRegistrar($app, $container, $secrets)->register($drivers);
        new AuthPasskeyRegistrar($app, $container)->register($drivers);
        new AuthNotificationRegistrar($app, $container)->register($drivers);
        new AuthManagerRegistrar($app, $container)->register();
        new AuthAuthorizationRegistrar($app, $container)->register();
        new AuthRuntimeRegistrar($app, $container)->register();
    }
}
