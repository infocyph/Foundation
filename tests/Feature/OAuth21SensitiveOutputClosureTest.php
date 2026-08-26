<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;

it('keeps protocol credentials and authorization headers out of HTTP error output', function (): void {
    $secret = 'client-secret-output-sentinel';
    $authorization = 'Basic ' . base64_encode('oc_client:' . $secret . "\x01");
    $request = new Request(
        method: 'POST',
        uri: '/oauth/token',
        headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => $authorization,
        ],
        body: new Stream('grant_type=client_credentials'),
    );
    $input = new OAuthHttpInput();

    try {
        $input->clientAuthentication($request, $input->form($request));
        throw new RuntimeException('Expected invalid Basic client credentials.');
    } catch (OAuthProtocolException $exception) {
        $response = new OAuthHttpResponseFactory()->error($exception);
        $output = $exception->getMessage() . "\n" . (string) $response->getBody();
        expect($output)
            ->not->toContain($secret)
            ->not->toContain($authorization);
    }
});

it('keeps all OAuth secret classes out of audit metadata and signing-key errors', function (): void {
    $capture = new OAuthAuditCapture();
    $sentinels = [
        'client_secret' => 'client-secret-sentinel',
        'code' => 'authorization-code-sentinel',
        'refresh_token' => 'refresh-token-sentinel',
        'access_token' => 'access-token-sentinel',
        'code_verifier' => 'pkce-verifier-sentinel',
        'private_key' => 'private-key-material-sentinel',
        'authorization' => 'Bearer authorization-header-sentinel',
        'private_key_path' => '/sensitive/private/oauth-signing-key.pem',
    ];
    $capture->recorder()->record(AuthEventType::OAUTH_INVALID_REQUEST, metadata: [
        'client_id' => 'oc_safe',
        'reason' => 'security-closure',
        ...$sentinels,
    ]);

    $auditOutput = json_encode($capture->events, JSON_THROW_ON_ERROR);
    foreach ($sentinels as $value) {
        expect($auditOutput)->not->toContain($value);
    }

    $config = AuthDefaults::all();
    $config['auth']['oauth']['issuer'] = 'https://issuer.example.test';
    $config['auth']['oauth']['signing']['active_key_id'] = 'active_key';
    $config['auth']['oauth']['signing']['private_key'] = $sentinels['private_key_path'];
    $config['auth']['oauth']['signing']['public_keys'] = [[
        'id' => 'active_key',
        'path' => '/sensitive/public/oauth-signing-key.pem',
        'status' => 'active',
    ]];

    try {
        new OAuthSigningKeyResolver(new ConfigRepository($config))->resolve();
        throw new RuntimeException('Expected unavailable signing-key material.');
    } catch (Throwable $exception) {
        expect($exception->getMessage())
            ->not->toContain($sentinels['private_key_path'])
            ->not->toContain('/sensitive/public/oauth-signing-key.pem')
            ->not->toContain($sentinels['private_key']);
    }
});

it('has no direct OAuth logger channel that could bypass the sanitized audit recorder', function (): void {
    $root = dirname(__DIR__, 2) . '/src/Auth/OAuth';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $forbidden = [
        'LoggerInterface',
        '->debug(',
        '->info(',
        '->notice(',
        '->warning(',
        '->error(',
        '->critical(',
        '->alert(',
        '->emergency(',
    ];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        foreach ($forbidden as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});
