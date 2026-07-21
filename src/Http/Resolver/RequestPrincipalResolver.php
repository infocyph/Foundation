<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;

final readonly class RequestPrincipalResolver
{
    /** @var list<PrincipalResolverInterface> */
    private array $orderedResolvers;

    /**
     * @param array<string, PrincipalResolverInterface> $resolvers
     */
    public function __construct(
        ConfigRepository $config,
        array $resolvers,
    ) {
        $order = $config->get('auth.http.principal_resolvers', []);
        $ordered = [];

        if (is_array($order)) {
            foreach ($order as $name) {
                if (is_string($name) && ($resolvers[$name] ?? null) instanceof PrincipalResolverInterface) {
                    $ordered[] = $resolvers[$name];
                }
            }
        }

        $this->orderedResolvers = $ordered !== [] ? $ordered : array_values($resolvers);
    }

    public function resolve(Request $request): ?PrincipalInterface
    {
        foreach ($this->orderedResolvers as $resolver) {
            $principal = $resolver->resolve($request);
            if ($principal !== null) {
                return $principal;
            }
        }

        return null;
    }
}
