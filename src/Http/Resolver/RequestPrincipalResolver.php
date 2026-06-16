<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;

final readonly class RequestPrincipalResolver
{
    /**
     * @param array<string, PrincipalResolverInterface> $resolvers
     */
    public function __construct(
        private ConfigRepository $config,
        private array $resolvers,
    ) {}

    public function resolve(Request $request): ?PrincipalInterface
    {
        foreach ($this->orderedResolvers() as $resolver) {
            $principal = $resolver->resolve($request);
            if ($principal !== null) {
                return $principal;
            }
        }

        return null;
    }

    /**
     * @return list<PrincipalResolverInterface>
     */
    private function orderedResolvers(): array
    {
        $order = $this->config->get('auth.http.principal_resolvers', []);
        $ordered = [];

        if (is_array($order)) {
            foreach ($order as $name) {
                if (!is_string($name)) {
                    continue;
                }

                $resolver = $this->resolvers[$name] ?? null;
                if ($resolver instanceof PrincipalResolverInterface) {
                    $ordered[] = $resolver;
                }
            }
        }

        if ($ordered !== []) {
            return $ordered;
        }

        return array_values($this->resolvers);
    }
}
