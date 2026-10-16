<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Communication\CommunicationServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Omnibus\MessageBus;
use Infocyph\TalkingBytes\Http\HttpClient;

/**
 * @param array<string,bool> $features
 * @return array{context:FoundationBuildContext,classes:list<string>,builder:\Infocyph\InterMix\DI\ContainerBuilder}
 */
function moduleB5ExecutionGraph(array $features): array
{
    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['env' => 'testing', 'capabilities' => ['messaging', 'communication', 'runwire']],
        'modules' => ['features' => $features],
    ]), RuntimeMode::Worker);
    $builder = FoundationGraph::compose($context);
    $registry = new ServiceRegistry();
    new Bootstrapper()->compose($builder, $context, $registry);

    return ['context' => $context, 'classes' => $registry->classes(), 'builder' => $builder];
}

it('keeps Omnibus and TalkingBytes selectable while Runwire is Core', function (): void {
    foreach ([
        ['features' => [], 'messaging' => false, 'communication' => false],
        ['features' => ['execution.messaging' => true], 'messaging' => true, 'communication' => false],
        ['features' => ['execution.communication' => true], 'messaging' => false, 'communication' => true],
        ['features' => ['execution.messaging' => true, 'execution.communication' => true], 'messaging' => true, 'communication' => true],
    ] as $case) {
        $graph = moduleB5ExecutionGraph($case['features']);

        expect(in_array(MessagingServiceProvider::class, $graph['classes'], true))->toBe($case['messaging'])
            ->and(in_array(CommunicationServiceProvider::class, $graph['classes'], true))->toBe($case['communication'])
            ->and($graph['builder']->definitionGraph()->hasDefinition(MessageBus::class))->toBe($case['messaging'])
            ->and($graph['builder']->definitionGraph()->hasDefinition(HttpClient::class))->toBe($case['communication'])
            ->and($graph['context']->permitsCapability('runwire'))->toBeTrue();
    }
});

it('requires explicit DBLayer for durable Omnibus independently of Core Runwire', function (): void {
    expect(fn() => FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => ['database', 'messaging', 'runwire']],
        'messaging' => ['durable' => ['enabled' => true]],
        'modules' => ['features' => ['execution.messaging' => true]],
    ]), RuntimeMode::Worker))->toThrow(LogicException::class, 'data.database');

    $context = FoundationBuildContext::fromConfig(new ConfigRepository([
        'app' => ['capabilities' => ['database', 'messaging', 'runwire']],
        'messaging' => ['durable' => ['enabled' => true]],
        'modules' => ['features' => ['data.database' => true, 'execution.messaging' => true]],
    ]), RuntimeMode::Worker);

    expect($context->hasFeature('data.database'))->toBeTrue()
        ->and($context->hasFeature('execution.messaging'))->toBeTrue()
        ->and($context->permitsCapability('runwire'))->toBeTrue();
});
