<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;

[$directory, $kind, $worker, $now] = array_slice($argv, 1);
$connection = json_decode((string) file_get_contents($directory . '/connection.json'), true, flags: JSON_THROW_ON_ERROR);
try {
    $fixture = new AuthContentionFixture($connection);
    $result = $fixture->compete($kind, $directory, $worker, (int) $now);
    file_put_contents($directory . '/result-' . $worker, json_encode(['changed' => $result], JSON_THROW_ON_ERROR));
} catch (Throwable $failure) {
    file_put_contents($directory . '/result-' . $worker, json_encode([
        'error' => $failure::class . ': ' . $failure->getMessage(),
    ], JSON_THROW_ON_ERROR));
    throw $failure;
}
