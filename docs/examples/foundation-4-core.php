<?php

declare(strict_types=1);

// From repository checkout: php docs/examples/foundation-4-core.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;

$app = Foundation::cli([
    'app' => ['base_path' => dirname(__DIR__, 2), 'env' => 'local', 'capabilities' => []],
    '_config_cache' => false,
])->boot();

$app->execution()->run(static function (ExecutionId $id): void {
    printf("Foundation 4 CLI execution: %s\n", (string) $id);
});
