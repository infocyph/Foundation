<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\TalkingBytes\Email\EmailMessage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Run only in the dedicated GitHub Actions Mailpit service lane.
// Unlike a fake transport, this verifies actual SMTP DATA receipt through
// Mailpit's separate HTTP inspection API.
$subject = 'Foundation Mailpit ' . bin2hex(random_bytes(8));
$base = dirname(__DIR__, 2);
$app = Foundation::worker([
    'app' => ['base_path' => $base, 'load_env' => false, 'capabilities' => ['notifications']],
    '_config_cache' => false,
    'notifications' => [
        'email' => [
            'default_sender' => 'mailpit',
            'senders' => ['mailpit' => ['transport' => 'mailpit']],
            'transports' => [
                'mailpit' => [
                    'driver' => 'smtp',
                    'host' => '127.0.0.1',
                    'port' => 1025,
                    'security' => 'none',
                    'timeoutSeconds' => 5,
                ],
            ],
        ],
    ],
])->boot();

$result = $app->execution()->run(
    static fn() => $app->make(EmailProfiles::class)->sender()->send(
        EmailMessage::new()
            ->from('foundation@example.test')
            ->to('recipient@example.test')
            ->subject($subject)
            ->text('Actual SMTP DATA from a Foundation/TalkingBytes production profile.'),
    ),
);
if (!$result->successful) {
    throw new RuntimeException('TalkingBytes SMTP send failed: ' . (string) $result->error);
}

$found = false;
for ($attempt = 0; $attempt < 30; ++$attempt) {
    $curl = curl_init('http://127.0.0.1:8025/api/v1/messages');
    if (!$curl instanceof CurlHandle) {
        throw new RuntimeException('Unable to inspect Mailpit messages.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    // CurlHandle closes automatically when the scoped object is released.
    if (is_string($body) && $status === 200) {
        $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $messages = is_array($page) ? ($page['messages'] ?? []) : [];
        if (!is_array($messages)) {
            throw new RuntimeException('Mailpit returned an unexpected message-list shape.');
        }
        foreach ($messages as $message) {
            if (is_array($message) && ($message['Subject'] ?? null) === $subject) {
                $found = true;
                break 2;
            }
        }
    }
    usleep(100_000);
}

if (!$found) {
    throw new RuntimeException('The actual SMTP message was not captured by Mailpit.');
}

fwrite(STDOUT, "Foundation native TalkingBytes SMTP -> Mailpit delivery verified.\n");
