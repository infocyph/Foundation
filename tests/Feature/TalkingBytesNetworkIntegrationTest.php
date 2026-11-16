<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

it('runs configured TalkingBytes HTTP retries, redirects, multipart uploads and a concurrent pool against a real loopback peer', function (bool $precreatedHandshake): void {
    $ready = tempnam(sys_get_temp_dir(), 'foundation-talkingbytes-http-');
    if ($ready === false) {
        throw new RuntimeException('Unable to allocate TalkingBytes HTTP fixture handshake.');
    }
    if (!$precreatedHandshake) {
        unlink($ready);
    }

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/Fixtures/talkingbytes_network_server.php', $ready, $precreatedHandshake ? 'delay-ready' : 'ready'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to spawn TalkingBytes loopback peer.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[2], false);

    try {
        $until = hrtime(true) + 10_000_000_000;
        $port = null;
        do {
            clearstatcache(true, $ready);
            $server = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
            $port = is_array($server) ? ($server['port'] ?? null) : null;
            if (is_int($port) && $port > 0 && $port <= 65535) {
                break;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(10_000);
        } while (hrtime(true) < $until);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            $diagnostics = trim((string) stream_get_contents($pipes[2]));
            throw new RuntimeException('TalkingBytes loopback HTTP server did not become ready.'
                . ($diagnostics === '' ? '' : ' Child stderr: ' . $diagnostics));
        }
        $url = 'http://127.0.0.1:' . $port;
        $profiles = new CommunicationProfiles(new ConfigRepository([
            'communication' => [
                'http' => [
                    'default_client' => 'tested',
                    'clients' => [
                        'tested' => [
                            'timeoutSeconds' => 5,
                            'connectTimeoutSeconds' => 2,
                            'followRedirects' => true,
                            'maxRedirects' => 3,
                            'verifyPeer' => true,
                            'verifyHost' => true,
                            'retry' => ['enabled' => true, 'attempts' => 2, 'base_delay_ms' => 0],
                        ],
                    ],
                ],
            ],
        ]));

        $http = $profiles->http();
        $retried = $http->get($url . '/retry');
        \PHPUnit\Framework\Assert::assertInstanceOf(HttpResponse::class, $retried->response);
        expect($retried->successful)->toBeTrue()
            ->and($retried->statusCode)->toBe(200)

            ->and($retried->response->json())->toMatchArray(['attempt' => 2]);

        $redirected = $http->get($url . '/redirect');
        \PHPUnit\Framework\Assert::assertInstanceOf(HttpResponse::class, $redirected->response);
        expect($redirected->successful)->toBeTrue()

            ->and($redirected->response->json())->toMatchArray(['path' => '/pool-a']);

        $file = tempnam(sys_get_temp_dir(), 'foundation-multipart-source-');
        if ($file === false) {
            throw new RuntimeException('Unable to allocate TalkingBytes upload source.');
        }
        file_put_contents($file, 'foundation-upload-probe');

        try {
            $multipart = HttpClient::multipart()
                ->addField('tag', 'network-fixture')
                ->addFile('attachment', $file, 'text/plain', 'fixture.txt');
            $uploaded = $http->postMultipart($url . '/multipart', $multipart);
            \PHPUnit\Framework\Assert::assertInstanceOf(HttpResponse::class, $uploaded->response);
            expect($uploaded->successful)->toBeTrue()

                ->and($uploaded->response->json())->toMatchArray([
                    'contains_file' => true,
                    'contains_field' => true,
                    'contains_filename' => true,
                ])
                ->and(is_file($file))->toBeTrue();
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $pool = HttpClient::multi(2)->sendMany([
            'alpha' => HttpRequest::get($url . '/pool-a'),
            'beta' => HttpRequest::get($url . '/pool-b'),
        ]);
        $alpha = $pool->get('alpha')?->response;
        $beta = $pool->get('beta')?->response;
        \PHPUnit\Framework\Assert::assertInstanceOf(HttpResponse::class, $alpha);
        \PHPUnit\Framework\Assert::assertInstanceOf(HttpResponse::class, $beta);
        expect($pool->successfulCount())->toBe(2)
            ->and($pool->failedCount())->toBe(0)

            ->and($alpha->json())->toMatchArray(['path' => '/pool-a'])
            ->and($beta->json())->toMatchArray(['path' => '/pool-b']);

        $failed = $http->get($url . '/server-error');
        expect($failed->successful)->toBeFalse()
            ->and($failed->statusCode)->toBe(500)
            ->and($failed->response)->toBeInstanceOf(HttpResponse::class);
    } finally {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (is_file($ready)) {
            unlink($ready);
        }
    }
})->with(['unpublished handshake' => false, 'empty handshake before publication' => true]);
