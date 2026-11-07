<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

it('runs configured TalkingBytes HTTP retries, redirects, multipart uploads and a concurrent pool against a real loopback peer', function (): void {
    $ready = tempnam(sys_get_temp_dir(), 'foundation-talkingbytes-http-');
    if ($ready === false) {
        throw new RuntimeException('Unable to allocate TalkingBytes HTTP fixture handshake.');
    }
    unlink($ready);

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/Fixtures/talkingbytes_network_server.php', $ready],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to spawn TalkingBytes loopback peer.');
    }
    fclose($pipes[0]);

    try {
        $until = microtime(true) + 4.0;
        while (!is_file($ready) && microtime(true) < $until) {
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(10_000);
        }
        $server = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
        $port = is_array($server) ? ($server['port'] ?? null) : null;
        if (!is_int($port) || $port < 1) {
            throw new RuntimeException('TalkingBytes loopback HTTP server did not become ready.');
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
        expect($retried->successful)->toBeTrue()
            ->and($retried->statusCode)->toBe(200)
            ->and($retried->response)->toBeInstanceOf(HttpResponse::class)
            ->and($retried->response->json())->toMatchArray(['attempt' => 2]);

        $redirected = $http->get($url . '/redirect');
        expect($redirected->successful)->toBeTrue()
            ->and($redirected->response)->toBeInstanceOf(HttpResponse::class)
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
            expect($uploaded->successful)->toBeTrue()
                ->and($uploaded->response)->toBeInstanceOf(HttpResponse::class)
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
        expect($pool->successfulCount())->toBe(2)
            ->and($pool->failedCount())->toBe(0)
            ->and($pool->get('alpha')?->response)->toBeInstanceOf(HttpResponse::class)
            ->and($pool->get('alpha')?->response->json())->toMatchArray(['path' => '/pool-a'])
            ->and($pool->get('beta')?->response->json())->toMatchArray(['path' => '/pool-b']);

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
});
