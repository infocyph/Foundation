<?php

declare(strict_types=1);

/**
 * Isolated real TCP peer for Foundation/TalkingBytes integration tests.
 * No production code, external network or HTTP fixture substitution involved.
 */
require_once __DIR__ . '/ProbeInput.php';
$arguments = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::arguments($_SERVER['argv'] ?? []);
$readyPath = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::path($arguments[1] ?? '');
if ($readyPath === '') {
    throw new RuntimeException('Missing TalkingBytes HTTP fixture handshake path.');
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
if ($listener === false) {
    throw new RuntimeException(sprintf('Unable to create loopback server: %s (%d)', $message, $errno));
}
$address = stream_socket_get_name($listener, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fclose($listener);

    throw new RuntimeException('Unable to resolve loopback HTTP endpoint.');
}
$delimiter = strrchr($address, ':');
if ($delimiter === false) {
    throw new RuntimeException('Loopback endpoint has no port delimiter.');
}
$port = (int) substr($delimiter, 1);
file_put_contents($readyPath, json_encode(['port' => $port], JSON_THROW_ON_ERROR));

$attempts = 0;
$until = microtime(true) + 15.0;
$requests = 0;
while ($requests < 24 && microtime(true) < $until) {
    $peer = stream_socket_accept($listener, 0.1);
    if ($peer === false) {
        continue;
    }
    stream_set_timeout($peer, 3);
    ++$requests;

    $incoming = '';
    while (!str_contains($incoming, "\r\n\r\n") && !feof($peer) && strlen($incoming) <= 32768) {
        $chunk = fread($peer, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $incoming .= $chunk;
    }
    $separator = strpos($incoming, "\r\n\r\n");
    if ($separator === false) {
        fclose($peer);

        continue;
    }

    $head = substr($incoming, 0, $separator);
    $body = substr($incoming, $separator + 4);
    $firstLine = strtok($head, "\r\n");
    if ($firstLine === false || $firstLine === '0') {
        $firstLine = '';
    }
    $path = '/';
    if (preg_match('~^[A-Z]+ (\S+) HTTP/~D', $firstLine, $matches) === 1) {
        $parsed = parse_url($matches[1], PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';
    }
    $length = 0;
    foreach (explode("\r\n", $head) as $line) {
        if (stripos($line, 'content-length:') === 0) {
            $length = max(0, (int) trim(substr($line, 15)));

            break;
        }
    }
    while (!feof($peer)) {
        $remaining = $length - strlen($body);
        if ($remaining <= 0) {
            break;
        }
        $chunk = fread($peer, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    $code = 200;
    $headers = ['Content-Type' => 'application/json'];
    if ($path === '/retry') {
        ++$attempts;
        if ($attempts === 1) {
            $code = 503;
        }
        $result = ['attempt' => $attempts];
    } elseif ($path === '/redirect') {
        $code = 302;
        $headers['Location'] = "http://127.0.0.1:{$port}/pool-a";
        $result = ['redirect' => true];
    } elseif ($path === '/multipart') {
        $result = [
            'contains_file' => str_contains($body, 'foundation-upload-probe'),
            'contains_field' => str_contains($body, 'network-fixture'),
            'contains_filename' => str_contains($body, 'fixture.txt'),
            'body_bytes' => strlen($body),
        ];
    } elseif ($path === '/pool-a' || $path === '/pool-b') {
        $result = ['path' => $path];
    } elseif ($path === '/server-error') {
        $code = 500;
        $result = ['error' => 'synthetic upstream error'];
    } else {
        $code = 404;
        $result = ['error' => 'unknown test route'];
    }

    $payload = json_encode($result, JSON_THROW_ON_ERROR);
    $reason = match ($code) {
        302 => 'Found',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => 'OK',
    };
    $response = "HTTP/1.1 {$code} {$reason}\r\n";
    foreach ($headers as $name => $value) {
        $response .= "{$name}: {$value}\r\n";
    }
    $response .= 'Content-Length: ' . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload;
    fwrite($peer, $response);
    fclose($peer);
}
fclose($listener);
