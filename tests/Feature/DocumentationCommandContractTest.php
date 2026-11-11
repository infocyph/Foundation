<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandCatalog;
use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\CompletionGenerator;

it('keeps documented infbyte command examples dispatchable', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = [$root . '/README.md', ...((array) glob($root . '/docs/*.md'))];
    $documented = [];

    foreach ($documents as $path) {
        $contents = file_get_contents($path);
        expect($contents)->toBeString();

        preg_match_all('/\bphp\s+infbyte\s+([a-z][a-z0-9:_-]*)/i', $contents, $matches);
        foreach ($matches[1] ?? [] as $command) {
            $documented[strtolower($command)] = $path;
        }
    }

    $available = [
        ...array_keys(new CommandCatalog()->all()),
        'list',
        'help',
        'completion',
    ];
    $missing = array_values(array_diff(array_keys($documented), $available));

    expect($missing)->toBe([]);
});

it('generates a stable Zsh function name for a hyphenated executable', function (): void {
    $completion = new CompletionGenerator(new CommandRegistry(includeSystem: false));
    $script = $completion->generate('zsh', 'inf-byte');

    expect($script)->toContain('_inf_byte() {')
        ->and($script)->toContain('compdef _inf_byte inf-byte');
});
