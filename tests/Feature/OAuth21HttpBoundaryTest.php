<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;

function oauthHttpRequest(string $body, array $headers = [], string $uri = '/oauth/token'): Request
{
    return new Request(
        method: 'POST',
        uri: $uri,
        headers: $headers,
        body: new Stream($body),
    );
}

function oauthHttpClient(): OAuthClient
{
    return new OAuthClient(
        id: 'internal-client-id',
        clientId: 'oc_test',
        type: OAuthClientType::Confidential,
        authenticationMethod: OAuthClientAuthenticationMethod::ClientSecretBasic,
        secretHash: 'never-output-this-hash',
        grants: [OAuthGrantType::AuthorizationCode],
        audiences: ['https://api.example.test'],
        enabled: true,
        createdAt: 100,
        updatedAt: 100,
    );
}

it('rejects duplicate malformed and oversized encoded OAuth parameters', function (): void {
    $input = new OAuthHttpInput(maximumQueryBytes: 32);

    expect(fn() => $input->parseEncoded('scope=read&scope=write', 32))
        ->toThrow(OAuthProtocolException::class, 'invalid_request')
        ->and(fn() => $input->parseEncoded('scope=%ZZ', 32))
        ->toThrow(OAuthProtocolException::class, 'invalid_request')
        ->and(fn() => $input->parseEncoded('scope=' . str_repeat('x', 40), 32))
        ->toThrow(OAuthProtocolException::class, 'invalid_request');
});

it('requires body-only urlencoded parameters and rejects credential downgrade parameters', function (): void {
    $input = new OAuthHttpInput();

    expect(fn() => $input->form(oauthHttpRequest('grant_type=client_credentials', ['Content-Type' => 'application/json'])))
        ->toThrow(OAuthProtocolException::class, 'invalid_request')
        ->and(fn() => $input->form(oauthHttpRequest(
            'grant_type=client_credentials',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            '/oauth/token?client_id=oc_test',
        )))->toThrow(OAuthProtocolException::class, 'invalid_request')
        ->and(fn() => $input->form(oauthHttpRequest(
            'grant_type=client_credentials&client_id=oc_test&client_secret=secret',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        )))->toThrow(OAuthProtocolException::class, 'invalid_request');
});

it('parses client_secret_basic and rejects mixed client identity sources', function (): void {
    $input = new OAuthHttpInput();
    $authorization = 'Basic ' . base64_encode(rawurlencode('client:id') . ':' . rawurlencode('s ecret'));
    $request = oauthHttpRequest(
        'grant_type=client_credentials',
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => $authorization,
        ],
    );
    $parameters = $input->form($request);
    $authentication = $input->clientAuthentication($request, $parameters);

    expect($authentication->method)->toBe(OAuthClientAuthenticationMethod::ClientSecretBasic)
        ->and($authentication->clientId)->toBe('client:id')
        ->and($authentication->secret)->toBe('s ecret');

    $mixed = oauthHttpRequest(
        'grant_type=client_credentials&client_id=client%3Aid',
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => $authorization,
        ],
    );

    expect(fn() => $input->clientAuthentication($mixed, $input->form($mixed)))
        ->toThrow(OAuthProtocolException::class, 'invalid_request');
});

it('adds no-store headers and a Basic challenge to OAuth client authentication errors', function (): void {
    $response = new OAuthHttpResponseFactory()->error(OAuthProtocolException::invalidClient());

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($response->getHeaderLine('Pragma'))->toBe('no-cache')
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe('Basic realm="oauth"')
        ->and((string) $response->getBody())->not->toContain('secret');
});

it('preserves registered redirect query data while adding code state and RFC 9207 issuer', function (): void {
    $client = oauthHttpClient();
    $request = new AuthorizationRequest(
        client: $client,
        redirectUri: 'https://client.example.test/callback?tenant=acme',
        codeChallenge: str_repeat('A', 43),
        scopes: ['read'],
        audiences: ['https://api.example.test'],
        requiredPermissions: [],
        state: 'state value',
    );

    $response = new OAuthHttpResponseFactory()->authorizationSuccess(
        $request,
        'code-value',
        'https://issuer.example.test',
    );
    $location = $response->getHeaderLine('Location');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($location)->toStartWith('https://client.example.test/callback?tenant=acme&')
        ->and($location)->toContain('code=code-value')
        ->and($location)->toContain('state=state%20value')
        ->and($location)->toContain('iss=https%3A%2F%2Fissuer.example.test');
});

it('never redirects non-redirectable OAuth errors and omits an unsafe state value', function (): void {
    $factory = new OAuthHttpResponseFactory();
    $client = oauthHttpClient();
    $redirect = new AuthorizationRedirectContext($client, 'https://client.example.test/callback', null);

    $unsafe = $factory->authorizationError(
        $redirect,
        OAuthProtocolException::invalidRequest('The state parameter is invalid.', true),
        'https://issuer.example.test',
    );
    $notRedirectable = $factory->authorizationError(
        $redirect,
        OAuthProtocolException::invalidRequest('The redirect URI is invalid.'),
        'https://issuer.example.test',
    );

    expect($unsafe->getStatusCode())->toBe(302)
        ->and($unsafe->getHeaderLine('Location'))->not->toContain('state=')
        ->and($notRedirectable->getStatusCode())->toBe(400)
        ->and($notRedirectable->getHeaderLine('Location'))->toBe('');
});
