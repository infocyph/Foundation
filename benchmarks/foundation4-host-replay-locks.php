<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/Fixtures/ProbeInput.php';

use Infocyph\Foundation\Tests\Fixtures\ProbeInput;

$arguments = ProbeInput::fromServer($_SERVER);
if (count($arguments) !== 4 || preg_match('/\A[a-f0-9]{64}\z/D', $arguments[2]) !== 1) {
    throw new InvalidArgumentException('Usage: replay-locks INPUT_DIRECTORY REVIEWED_SHA256 OUTPUT_DIRECTORY');
}

$input = ProbeInput::path($arguments[1]);
$output = ProbeInput::path($arguments[3]);
$captured = [];
foreach (['baseline.lock', 'candidate.lock'] as $name) {
    $contents = file_get_contents(ProbeInput::path($input . '/' . $name));
    if ($contents === false || !hash_equals($arguments[2], hash('sha256', $contents))) {
        throw new RuntimeException('Reviewed shared-lock digest mismatch: ' . $name);
    }
    $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($lock) || !is_array($lock['packages'] ?? null) || !is_array($lock['packages-dev'] ?? null)) {
        throw new RuntimeException('Reviewed artifact must contain complete Composer lock files.');
    }
    $captured[$name] = $contents;
}

if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
    throw new RuntimeException('Unable to create the frozen-lock output directory.');
}
foreach ($captured as $name => $contents) {
    $path = ProbeInput::path($output . '/' . $name);
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('Unable to write the verified lock snapshot.');
    }
}
fwrite(STDOUT, 'Verified shared lock ' . $arguments[2] . PHP_EOL);
