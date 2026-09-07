<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\JsonDispatch;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

final class JsonDispatchServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $responses = is_array($context->config['responses'] ?? null) ? $context->config['responses'] : [];
        $json = is_array($responses['json_dispatch'] ?? null) ? $responses['json_dispatch'] : [];

        $builder->singleton(
            JsonDispatchResponseFactory::class,
            FactoryDefinition::construct(JsonDispatchResponseFactory::class, [
                ValueNormalizer::string($json['vendor'] ?? null, 'infocyph'),
                ValueNormalizer::string($json['application_version'] ?? null, '1.0.0'),
                ValueNormalizer::bool($json['tunnel_errors'] ?? null, false),
            ]),
        );
        $builder->alias('foundation.responses', JsonDispatchResponseFactory::class);
    }
}
