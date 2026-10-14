<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\InterMix\DI\ContainerBuilder;

final class FoundationB4FirstProvider extends ServiceProvider
{
    /** @var list<string> */
    public static array $order = [];

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($builder, $context);
        self::$order[] = 'first';
    }
}

final class FoundationB4SecondProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($builder, $context);
        FoundationB4FirstProvider::$order[] = 'second';
    }
}

final class FoundationB4LateProvider extends ServiceProvider
{
    public function __construct(private readonly ServiceRegistry $registry) {}

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($builder, $context);
        $this->registry->add(new FoundationB4SecondProvider());
    }
}

it('seals the exact provider list before graph contribution and preserves contribution order', function (): void {
    FoundationB4FirstProvider::$order = [];
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => []],
    ]), RuntimeMode::Cli);
    $registry = new ServiceRegistry();
    $registry->add(new FoundationB4FirstProvider());
    $registry->add(new FoundationB4SecondProvider());
    $original = $registry->classes();

    $registry->contribute(FoundationGraph::compose($context), $context);

    expect(FoundationB4FirstProvider::$order)->toBe(['first', 'second'])
        ->and($registry->classes())->toBe($original)
        ->and(fn() => $registry->add(new FoundationB4FirstProvider()))
        ->toThrow(LogicException::class, 'registration is closed');
});

it('contributes the same sealed provider ownership to a second production builder', function (): void {
    FoundationB4FirstProvider::$order = [];
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => []],
    ]), RuntimeMode::Worker);
    $registry = new ServiceRegistry();
    $registry->add(new FoundationB4FirstProvider());
    $registry->add(new FoundationB4SecondProvider());

    $registry->contribute(FoundationGraph::compose($context), $context);
    $registry->contribute(FoundationGraph::compose($context), $context);

    expect(FoundationB4FirstProvider::$order)->toBe(['first', 'second', 'first', 'second'])
        ->and($registry->classes())->toBe([
            FoundationB4FirstProvider::class,
            FoundationB4SecondProvider::class,
        ]);
});

it('rejects a provider registering new owners while a graph is being contributed', function (): void {
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => []],
    ]), RuntimeMode::Scheduler);
    $registry = new ServiceRegistry();
    $registry->add(new FoundationB4LateProvider($registry));

    expect(fn() => $registry->contribute(FoundationGraph::compose($context), $context))
        ->toThrow(LogicException::class, 'registration is closed')
        ->and($registry->classes())->toBe([FoundationB4LateProvider::class]);
});
