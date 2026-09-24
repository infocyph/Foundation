<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$attempts = [];
$prefixes = [
    'Infocyph\\DBLayer\\',
    'Infocyph\\Epicrypt\\',
    'Infocyph\\Omnibus\\',
    'Infocyph\\OTP\\',
    'Infocyph\\Pathwise\\',
    'Infocyph\\ReqShield\\',
    'Infocyph\\Runwire\\',
    'Infocyph\\TalkingBytes\\',
    'Webauthn\\',
];
spl_autoload_register(static function (string $class) use (&$attempts, $prefixes): void {
    if (array_any($prefixes, static fn(string $prefix): bool => str_starts_with($class, $prefix))) {
        $attempts[] = $class;
    }
}, prepend: true);

foreach (['web', 'cli', 'worker', 'scheduler'] as $mode) {
    Foundation::{$mode}([
        'base_path' => sys_get_temp_dir(),
        '_config_cache' => false,
        'app' => ['env' => 'testing', 'capabilities' => []],
    ])->boot();
}

fwrite(STDOUT, json_encode(array_values(array_unique($attempts)), JSON_THROW_ON_ERROR));
