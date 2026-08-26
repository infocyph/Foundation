<?php

declare(strict_types=1);

/**
 * F7 release guard: reject feature-branch additions that weaken engineering gates.
 *
 * The comparison is intentionally against the released Foundation 2.0 boundary.
 * Documentation/evidence files and this guard are excluded from the diff scan.
 */

/** @param list<string> $command */
function f7Run(array $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start F7 gate command.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException(trim((string) $stderr) ?: 'F7 gate command failed.');
    }

    return (string) $stdout;
}

$base = getenv('FOUNDATION_F7_BASE_REF') ?: '2.0';
$diff = f7Run([
    'git',
    'diff',
    '--unified=0',
    $base . '...HEAD',
    '--',
    '.',
    ':!docs/evidence/**',
    ':!foundation-plan.md',
    ':!tools/f7-no-weakened-gates.php',
]);
$nameStatus = f7Run(['git', 'diff', '--name-status', $base . '...HEAD']);

$forbidden = [
    '/@phpstan-ignore(?:-line|-next-line)?\b/i' => 'PHPStan suppression',
    '/@psalm-suppress\b/i' => 'Psalm suppression',
    '/\bignoreErrors\b/i' => 'static-analysis ignoreErrors rule',
    '/\bmarkTestSkipped\s*\(/i' => 'explicit skipped test',
    '/->skip\s*\(/i' => 'Pest skipped test',
    '/\bcontinue-on-error\s*:\s*true\b/i' => 'non-blocking CI step',
    '/\bfail_on_skipped_tests\s*:\s*false\b/i' => 'disabled skipped-test failure',
    '/\brun_analysis\s*:\s*false\b/i' => 'disabled analysis gate',
    '/--ignore-platform-reqs?\b/i' => 'ignored Composer platform requirement',
    '/--no-audit\b/i' => 'disabled Composer audit',
    '/--no-verify\b/i' => 'bypassed Git hooks',
    '/memory[-_ ]?limit[^\n]*(?:-1|[2-9]\d*G)\b/i' => 'unbounded or raised analyzer memory limit',
    '/benchmark[_-]max[_-]regression[_-]percent\s*[:=]\s*(?:[3-9]|\d{2,})(?:\.\d+)?\b/i' => 'raised benchmark regression budget',
];

$violations = [];
$currentFile = '(unknown)';
foreach (preg_split('/\R/', $diff) ?: [] as $line) {
    if (str_starts_with($line, '+++ b/')) {
        $currentFile = substr($line, 6);

        continue;
    }

    if (!str_starts_with($line, '+') || str_starts_with($line, '+++')) {
        continue;
    }

    $added = substr($line, 1);

    foreach ($forbidden as $pattern => $reason) {
        if (preg_match($pattern, $added) === 1) {
            $violations[] = sprintf('%s: %s: %s', $currentFile, $reason, trim($added));
        }
    }
}

foreach (preg_split('/\R/', trim($nameStatus)) ?: [] as $entry) {
    if ($entry === '') {
        continue;
    }

    $parts = preg_split('/\s+/', $entry, 2);
    $status = $parts[0] ?? '';
    $path = $parts[1] ?? '';

    if (!str_starts_with($status, 'A')) {
        continue;
    }

    if (preg_match('/(?:^|\/)(?:phpstan|psalm|phpunit|pest)[^\/]*baseline|\.baseline\b/i', $path) === 1) {
        $violations[] = sprintf('%s: newly added analysis/test baseline', $path);
    }
}

if ($violations !== []) {
    fwrite(STDERR, "F7 no-weakened-gates guard failed:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("F7 no-weakened-gates guard passed against %s.\n", $base));
