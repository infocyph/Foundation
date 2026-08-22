<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Worker\WorkerManager;

it('rejects a parent cache manager that may retain backend resources before pool fork', function (): void {
    $app = Foundation::worker([
        'cache' => [
            'default' => 'memory',
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
        'messaging' => [
            'workers' => [
                'parallel' => [
                    'transport' => 'shared',
                    'queue' => 'default',
                    'pool' => [
                        'enabled' => true,
                        'concurrency' => 2,
                    ],
                ],
            ],
        ],
    ]);

    $app->make(CacheManager::class)->store();

    expect(fn() => new WorkerManager($app)->run('parallel'))
        ->toThrow(LogicException::class, CacheManager::class);
});
