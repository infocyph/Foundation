<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[2] ?? 'valid';
$seen = [];
Runtime::create()->listen(Server::http($argv[1], static function (HttpRequest $request, ResponseWriterInterface $writer) use ($mode, &$seen): void {
    $badEvidence = $mode === 'invalid' || ($mode === 'measurement-invalid' && isset($seen[$request->peerAddress]));
    $seen[$request->peerAddress] = true;
    $writer->start(200, Headers::fromArray([
        'Content-Length' => '11',
        'X-Foundation-Benchmark-Queries' => $badEvidence ? '0' : '4',
        'X-Foundation-Benchmark-Cache-Hits' => '1',
        'X-Foundation-Benchmark-Worker' => (string) getmypid(),
    ]));
    $writer->end('{"ok":true}');
}))->run();
