<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\Foundation4HostComparison;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 4) {
    throw new InvalidArgumentException(
        'Usage: php foundation4-host-compare.php BASELINE.json CANDIDATE.json OUTPUT.json',
    );
}

$baseline = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$candidate = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($baseline) || !is_array($candidate)) {
    throw new InvalidArgumentException('Both Foundation 4 host reports must be JSON objects.');
}

$result = Foundation4HostComparison::compare($baseline, $candidate);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($argv[3], $json, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write Foundation 4 host comparison evidence.');
}

fwrite(STDOUT, $json);
if ($result['passed'] !== true) {
    throw new RuntimeException('Foundation 4 host regression budget or benchmark stability check failed.');
}
