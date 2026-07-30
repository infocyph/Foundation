<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\JsonDispatch;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class JsonDispatchServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $this->bindFactory(
            $app->container(),
            JsonDispatchResponseFactory::class,
            fn() => new JsonDispatchResponseFactory(
                vendor: ValueNormalizer::string($app->config()->get('responses.json_dispatch.vendor'), 'infocyph'),
                applicationVersion: ValueNormalizer::string(
                    $app->config()->get('responses.json_dispatch.application_version'),
                    '1.0.0',
                ),
                tunnelErrors: ValueNormalizer::bool(
                    $app->config()->get('responses.json_dispatch.tunnel_errors'),
                    false,
                ),
            ),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $app->container(),
            'foundation.responses',
            fn() => $app->make(JsonDispatchResponseFactory::class),
            LifetimeEnum::Singleton,
        );
    }
}
