<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenResponse;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthHttpResponseFactory
{
    private const array NO_STORE_HEADERS = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
    ];

    public function token(OAuthTokenResponse $token): Response
    {
        return Response::json($token->toArray(), 200, self::NO_STORE_HEADERS);
    }

    public function introspection(OAuthIntrospectionResult $result): Response
    {
        return Response::json($result->toArray(), 200, self::NO_STORE_HEADERS);
    }

    /** @param array<string, mixed> $metadata */
    public function metadata(array $metadata): Response
    {
        return Response::json($metadata, 200);
    }

    /** @param array{keys:list<array<string, mixed>>} $jwks */
    public function jwks(array $jwks): Response
    {
        return Response::json($jwks, 200);
    }

    public function revocation(): Response
    {
        return Response::empty(200, self::NO_STORE_HEADERS);
    }

    public function error(OAuthProtocolException $exception): Response
    {
        $headers = self::NO_STORE_HEADERS;
        if ($exception->error === 'invalid_client') {
            $headers['WWW-Authenticate'] = 'Basic realm="oauth"';
        }

        return Response::json([
            'error' => $exception->error,
            'error_description' => $exception->description,
        ], $exception->status, $headers);
    }

    public function authorizationSuccess(AuthorizationRequest $request, string $code, string $issuer): Response
    {
        $parameters = [
            'code' => $code,
            'iss' => $issuer,
        ];
        if ($request->state !== null) {
            $parameters['state'] = $request->state;
        }

        return Response::redirect($this->appendQuery($request->redirectUri, $parameters));
    }

    public function authorizationError(
        AuthorizationRedirectContext $redirect,
        OAuthProtocolException $exception,
        string $issuer,
    ): Response {
        if (!$exception->redirectAllowed) {
            return $this->error($exception);
        }

        $parameters = [
            'error' => $exception->error,
            'error_description' => $exception->description,
            'iss' => $issuer,
        ];
        if ($redirect->state !== null) {
            $parameters['state'] = $redirect->state;
        }

        return Response::redirect($this->appendQuery($redirect->redirectUri, $parameters));
    }

    /** @param array<string, string> $parameters */
    private function appendQuery(string $redirectUri, array $parameters): string
    {
        if (str_contains($redirectUri, '#')) {
            throw new \LogicException('Validated OAuth redirect URIs must not contain fragments.');
        }

        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        if ($encoded === '') {
            return $redirectUri;
        }

        if (!str_contains($redirectUri, '?')) {
            return $redirectUri . '?' . $encoded;
        }

        $separator = str_ends_with($redirectUri, '?') || str_ends_with($redirectUri, '&') ? '' : '&';

        return $redirectUri . $separator . $encoded;
    }
}
