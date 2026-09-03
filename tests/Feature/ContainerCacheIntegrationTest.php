<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

it('does not reactivate the legacy resolver-map container path from obsolete config switches', function (): void {
    $application = Foundation::web([
        '_config_cache' => false,
        'app' => [
            'container' => [
                'compiled' => 'bootstrap/cache/container/{runtime}.php',
                'compiled_activation' => 'always',
            ],
        ],
    ]);

    expect($application->container()->getRepository()->hasCompiledResolvers())->toBeFalse();
});