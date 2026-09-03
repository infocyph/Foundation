<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\ExecutionPlan;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use ReflectionMethod;

/**
 * Contributes only route-referenced invokable classes/methods to the web graph.
 * Route discovery remains exclusively Webrick's build-plane responsibility.
 */
final class WebRouteGraphEnricher
{
    /** @var array<string, true> */
    private array $registeredMethods = [];

    public function enrich(ContainerBuilder $builder, RouterBuildResult $routes): void
    {
        foreach ($routes->plans as $plan) {
            $this->contributePlan($builder, $plan);
        }
        foreach ($routes->preGlobal as $middleware) {
            $this->contributeResolver($builder, $middleware);
        }
        foreach ($routes->postGlobal as $middleware) {
            $this->contributeResolver($builder, $middleware);
        }
    }

    public function enrichArtifact(ContainerBuilder $builder, CompiledRouterArtifact $artifact): void
    {
        foreach ($artifact->routes() as $route) {
            $this->contributePlan($builder, $artifact->planForIndex($route->getIndex()));
        }
        foreach ($artifact->preGlobal() as $middleware) {
            $this->contributeResolver($builder, $middleware);
        }
        foreach ($artifact->postGlobal() as $middleware) {
            $this->contributeResolver($builder, $middleware);
        }
    }

    private function contributePlan(ContainerBuilder $builder, ExecutionPlan $plan): void
    {
        $this->contributeResolver($builder, $plan->resolverSpec());

        foreach ($plan->middleware as $middleware) {
            $this->contributeResolver($builder, $middleware);
        }
    }

    private function contributeResolver(ContainerBuilder $builder, mixed $resolver): void
    {
        if ($resolver instanceof RuntimeMiddlewareDescriptor) {
            $this->contributeResolver($builder, $resolver->resolverSpec());

            return;
        }

        if (is_string($resolver)) {
            if (class_exists($resolver) && method_exists($resolver, '__invoke')) {
                $this->contributeMethod($builder, $resolver, '__invoke');
            }

            return;
        }

        if (!is_array($resolver)
            || !array_is_list($resolver)
            || count($resolver) !== 2
            || !is_string($resolver[0] ?? null)
            || !is_string($resolver[1] ?? null)
            || !class_exists($resolver[0])
            || !method_exists($resolver[0], $resolver[1])
        ) {
            return;
        }

        $this->contributeMethod($builder, $resolver[0], $resolver[1]);
    }

    /** @param class-string $class */
    private function contributeMethod(ContainerBuilder $builder, string $class, string $method): void
    {
        $key = $class . '::' . $method;
        if (isset($this->registeredMethods[$key])) {
            return;
        }
        $this->registeredMethods[$key] = true;

        $reflection = new ReflectionMethod($class, $method);
        if (!$reflection->isStatic() && !$builder->definitions()->has($class)) {
            $builder->transient($class);
        }

        $builder->registration()->registerMethod($class, $method);
    }
}
