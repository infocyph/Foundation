<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('writes structured JSON and remains noninteractive after standard input closes', function (): void {
    $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
fclose(STDIN);
$io = new \Infocyph\Foundation\Command\TerminalIO();
if ($io->interactive()) {
    throw new \RuntimeException('Closed standard input cannot be interactive.');
}
$io->json(['values' => [false, null, 1.25, -7, (object) ['id' => 17]]]);
try {
    $io->json('scalar');
    throw new \RuntimeException('Command JSON requires a structured result.');
} catch (\TypeError) {
}
PHP], dirname(__DIR__, 3));
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and($process->getOutput())->toBe("{\"values\":[false,null,1.25,-7,{\"id\":17}]}\n");
});
