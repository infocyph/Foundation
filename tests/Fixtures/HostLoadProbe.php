<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$address = $argv[1] ?? null;
if (!is_string($address) || $address === '') {
    throw new InvalidArgumentException('Host load probe requires a listen address.');
}
$mode = $argv[2] ?? 'valid';
$seen = [];
Runtime::create()->listen(Server::http($address, static function (HttpRequest $request, ResponseWriterInterface $writer) use ($mode, &$seen): void {
    $peerAddress = $request->peerAddress;
    if ($peerAddress === null) {
        throw new RuntimeException('Host load probe requires an identifiable client peer.');
    }
    $badEvidence = $mode === 'invalid' || ($mode === 'measurement-invalid' && isset($seen[$peerAddress]));
    $seen[$peerAddress] = true;
    $writer->start(200, Headers::fromArray([
        'Content-Length' => '11',
        'X-Foundation-Benchmark-Queries' => $badEvidence ? '0' : '4',
        'X-Foundation-Benchmark-Cache-Hits' => '1',
        'X-Foundation-Benchmark-Worker' => (string) getmypid(),
    ]));
    $writer->end('{"ok":true}');
}))->run();
