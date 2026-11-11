<?php

declare(strict_types=1);

require_once __DIR__ . '/ProbeInput.php';
$argv = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::arguments($_SERVER['argv'] ?? []);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;

$directory = $argv[1] ?? throw new InvalidArgumentException('Missing CLI fixture argument.');
$kind = $argv[2] ?? throw new InvalidArgumentException('Missing CLI fixture argument.');
$worker = $argv[3] ?? throw new InvalidArgumentException('Missing CLI fixture argument.');
$now = $argv[4] ?? throw new InvalidArgumentException('Missing CLI fixture argument.');
$directory = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::path($directory);
$worker = match ($worker) {
    'first' => 'first', 'second' => 'second', default => throw new InvalidArgumentException('Unknown contention worker.')
};
$connection = json_decode((string) file_get_contents($directory . '/connection.json'), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($connection)) {
    throw new \UnexpectedValueException('Expected a decoded JSON object.');
}

try {
    $fixture = new AuthContentionFixture(\Infocyph\Foundation\Tests\Fixtures\ProbeInput::map($connection));
    $result = $fixture->compete($kind, $directory, $worker, (int) $now);
    file_put_contents($directory . '/result-' . $worker, json_encode(['changed' => $result], JSON_THROW_ON_ERROR));
} catch (Throwable $failure) {
    file_put_contents($directory . '/result-' . $worker, json_encode([
        'error' => $failure::class . ': ' . $failure->getMessage(),
    ], JSON_THROW_ON_ERROR));

    throw $failure;
}
