<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Foundation;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configuration = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$app = Foundation::web($configuration);
$app->container()->withinScope('webrick.request', static function () use ($app): void {
    $actions = $app->make(AuthActions::class);
    fwrite(STDOUT, 'READY' . PHP_EOL);
    $token = trim((string) fgets(STDIN));
    fwrite(STDOUT, ($actions->verifyPasswordless(['token' => $token])->successful() ? '1' : '0') . PHP_EOL);
});
