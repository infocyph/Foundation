<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Foundation;
use Infocyph\InterMix\DI\ContainerBuilder;

final readonly class Foundation4PublicGraphProbe {}

final class Foundation4PublicContractProvider extends ServiceProvider
{
    public static int $booted = 0;

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if ($context->runtimeMode !== RuntimeMode::Cli) {
            return;
        }

        $builder->factory(
            Foundation4PublicGraphProbe::class,
            static fn(): Foundation4PublicGraphProbe => new Foundation4PublicGraphProbe(),
        );
    }

    public function boot(Application $app): void
    {
        if ($app->runtimeMode() === RuntimeMode::Cli) {
            if (!$app->has(Foundation4PublicGraphProbe::class)) {
                throw new LogicException('Provider graph was not finalized before boot.');
            }

            ++self::$booted;
        }
    }
}

it('publishes the Foundation 4 provider graph contract as native InterMix builder input', function (): void {
    expect(is_subclass_of(Foundation4PublicContractProvider::class, ServiceProviderInterface::class))->toBeTrue();

    $method = new ReflectionMethod(ServiceProviderInterface::class, 'contribute');
    $parameters = $method->getParameters();
    $types = array_map(
        static fn(ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $parameters,
    );

    expect($types)->toBe([ContainerBuilder::class, FoundationBuildContext::class])
        ->and((string) $method->getReturnType())->toBe('void');

    Foundation4PublicContractProvider::$booted = 0;
    $app = Foundation::cli([
        '_config_cache' => false,
        'app' => ['env' => 'testing', 'capabilities' => []],
        'providers' => ['common' => [Foundation4PublicContractProvider::class]],
    ])->boot();

    expect($app->runtimeMode())->toBe(RuntimeMode::Cli)
        ->and($app->make(Foundation4PublicGraphProbe::class))
        ->toBeInstanceOf(Foundation4PublicGraphProbe::class)
        ->and(Foundation4PublicContractProvider::$booted)->toBe(1);
});

it('requires explicit selected capability policy rather than implicitly activating installed optional packages', function (): void {
    $config = new ConfigLoader()->load([
        '_config_cache' => false,
        'app' => ['env' => 'testing', 'capabilities' => ['database' => false, 'runwire' => false]],
    ]);
    $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Worker);

    expect($context->hasCapability('database'))->toBeFalse()
        ->and($context->permitsCapability('database'))->toBeFalse()
        ->and($context->hasCapability('runwire'))->toBeFalse()
        ->and($context->permitsCapability('runwire'))->toBeFalse();
});

it('retains distinct explicit Foundation 4 web CLI worker and scheduler entrypoints', function (): void {
    $config = ['_config_cache' => false, 'app' => ['env' => 'testing', 'capabilities' => []]];

    expect(Foundation::web($config)->runtimeMode())->toBe(RuntimeMode::Web)
        ->and(Foundation::cli($config)->runtimeMode())->toBe(RuntimeMode::Cli)
        ->and(Foundation::worker($config)->runtimeMode())->toBe(RuntimeMode::Worker)
        ->and(Foundation::scheduler($config)->runtimeMode())->toBe(RuntimeMode::Scheduler);
});
