<?php

declare(strict_types=1);

// From checkout after Composer installation:
// php docs/examples/foundation-4-runwire-worker.php
// Demonstrates host input identity, NOT a host listener or HTTP response emitter.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;

$app = Foundation::worker([
    'app' => [
        'base_path' => dirname(__DIR__, 2),
        'env' => 'local',
    ],
    '_config_cache' => false,
])->boot();

$runtime = RuntimeContext::standalone();
$request = RequestContext::create($runtime);

try {
    $app->execution()->run(
        static function (ExecutionId $id) use ($app, $runtime, $request): void {
            if ($app->make(RuntimeContext::class) !== $runtime
                || $app->make(RequestContext::class) !== $request
            ) {
                throw new LogicException('Host-owned Runwire context was not forwarded.');
            }

            printf("Foundation 4 Runwire worker execution: %s\n", (string) $id);
        },
        [RuntimeContext::class => $runtime, RequestContext::class => $request],
    );
} finally {
    // This standalone demonstration owns the request; in production the host does.
    $request->complete();
}
