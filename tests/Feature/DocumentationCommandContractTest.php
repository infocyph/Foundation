<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandCatalog;

it('keeps documented infbyte command examples dispatchable', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = [$root . '/README.md', ...glob($root . '/docs/*.md') ?: []];
    $documented = [];

    foreach ($documents as $path) {
        $contents = file_get_contents($path);
        expect($contents)->toBeString();

        preg_match_all('/\bphp\s+infbyte\s+([a-z][a-z0-9:_-]*)/i', $contents, $matches);
        foreach ($matches[1] ?? [] as $command) {
            $documented[strtolower($command)] = $path;
        }
    }

    $available = array_keys(new CommandCatalog()->all());
    $missing = array_values(array_diff(array_keys($documented), $available));

    expect($missing)->toBe([]);
});
