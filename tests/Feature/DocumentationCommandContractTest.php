<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\CompletionGenerator;
use Symfony\Component\Process\Process;

it('keeps documented infbyte command examples dispatchable', function (): void {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS),
        static fn(SplFileInfo|string $file): bool => $file instanceof SplFileInfo
            && (!$file->isDir() || !str_starts_with($file->getFilename(), '_')),
    ));
    $documents = [$root . '/README.md'];
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['md', 'rst'], true)) {
            $documents[] = $file->getPathname();
        }
    }
    $documented = [];

    foreach ($documents as $path) {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read documentation file: ' . $path);
        }
        preg_match_all('/\bphp\s+infbyte\s+([a-z][a-z0-9:_-]*)/i', $contents, $matches);
        foreach ($matches[1] as $command) {
            $documented[strtolower($command)] = $path;
        }
    }

    $probe = new Process([PHP_BINARY, '-r', <<<'PHP'
        require $argv[1];
        require $argv[2];
        $registry = new \Infocyph\Foundation\Command\CommandRegistry([
            'reports:daily' => \App\Command\Reports\DailyCommand::class,
        ]);
        echo implode("\n", array_keys($registry->all()));
        PHP,
        $root . '/vendor/autoload.php',
        $root . '/docs/examples/infbyte-report-command.php.txt',
    ]);
    $probe->mustRun();
    $available = [
        ...explode("\n", trim($probe->getOutput())),
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
