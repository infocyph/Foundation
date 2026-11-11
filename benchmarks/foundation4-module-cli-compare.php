<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\FoundationModuleCliComparison;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 4) {
    throw new InvalidArgumentException('Usage: php foundation4-module-cli-compare.php BASELINE.json CANDIDATE.json OUTPUT.json');
}
$baseline = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($baseline)) {
    throw new \UnexpectedValueException('Expected a decoded JSON object.');
}
$candidate = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($candidate)) {
    throw new \UnexpectedValueException('Expected a decoded JSON object.');
}
foreach ([$baseline, $candidate] as $report) {
    foreach (array_keys($report) as $key) {
        if (!is_string($key)) {
            throw new InvalidArgumentException('Module CLI reports require string keys.');
        }
    }
}
/** @var array<string,mixed> $baseline */
/** @var array<string,mixed> $candidate */
$result = FoundationModuleCliComparison::compare($baseline, $candidate);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($argv[3], $json, LOCK_EX) === false) {
    throw new RuntimeException('Unable to persist module CLI comparison.');
}
fwrite(STDOUT, $json);
