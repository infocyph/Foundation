<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ApiPreset implements FoundationPreset
{
    public function config(): array
    {
        return [
            'auth' => [
                'http' => [
                    'principal_resolvers' => ['bearer'],
                ],
            ],
            'router' => [
                'middleware_groups' => [
                    'api-auth' => ['resolve-auth', 'auth'],
                ],
            ],
        ];
    }
}
