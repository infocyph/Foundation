<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Webrick\Request\Request;

final readonly class OAuthHttpInput
{
    private const array FORBIDDEN_CREDENTIAL_PARAMETERS = [
        'client_secret',
        'client_assertion',
        'client_assertion_type',
    ];

    public function __construct(
        private int $maximumQueryBytes = 8192,
        private int $maximumFormBytes = 16384,
        private int $maximumParameters = 64,
        private int $maximumNameBytes = 128,
        private int $maximumValueBytes = 4096,
    ) {
        foreach ([
            $this->maximumQueryBytes,
            $this->maximumFormBytes,
            $this->maximumParameters,
            $this->maximumNameBytes,
            $this->maximumValueBytes,
        ] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('OAuth HTTP input limits must be positive.');
            }
        }
    }

    /** @return array<string, string> */
    public function authorizationQuery(Request $request): array
    {
        $parameters = $this->parseEncoded(
            $request->getUri()->getQuery(),
            $this->maximumQueryBytes,
        );
        $this->rejectCredentialParameters($parameters);

        return $parameters;
    }

    /** @param array<string, string> $parameters */
    public function clientAuthentication(Request $request, array $parameters): OAuthClientAuthentication
    {
        $headers = $request->getHeader('Authorization');
        if (count($headers) > 1) {
            throw OAuthProtocolException::invalidClient();
        }

        $authorization = $headers[0] ?? '';
        if ($authorization !== '') {
            if (array_key_exists('client_id', $parameters)) {
                throw OAuthProtocolException::invalidRequest('Client identity must not be supplied by multiple authentication sources.');
            }
            if (preg_match('/\ABasic[ \t]+([^ \t]+)\z/iD', $authorization, $match) !== 1) {
                throw OAuthProtocolException::invalidClient();
            }
            if (strlen($match[1]) > 8192) {
                throw OAuthProtocolException::invalidClient();
            }

            $decoded = base64_decode($match[1], true);
            if (!is_string($decoded) || !str_contains($decoded, ':')) {
                throw OAuthProtocolException::invalidClient();
            }
            [$encodedClientId, $encodedSecret] = explode(':', $decoded, 2);
            $clientId = $this->decodeComponent($encodedClientId);
            $secret = $this->decodeComponent($encodedSecret);
            if (!$this->validCredential($clientId, 128) || !$this->validCredential($secret, 4096)) {
                throw OAuthProtocolException::invalidClient();
            }

            return new OAuthClientAuthentication(
                OAuthClientAuthenticationMethod::ClientSecretBasic,
                $clientId,
                $secret,
            );
        }

        $clientId = $parameters['client_id'] ?? null;
        if (!is_string($clientId) || !$this->validCredential($clientId, 128)) {
            throw OAuthProtocolException::invalidClient();
        }

        return new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $clientId);
    }

    /** @return array<string, string> */
    public function form(Request $request): array
    {
        $query = $this->parseEncoded($request->getUri()->getQuery(), $this->maximumQueryBytes);
        if ($query !== []) {
            throw OAuthProtocolException::invalidRequest('OAuth protocol endpoint parameters must be sent in the request body.');
        }

        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0] ?? ''));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            throw OAuthProtocolException::invalidRequest('OAuth protocol endpoints require application/x-www-form-urlencoded.');
        }

        $parameters = $this->parseEncoded((string) $request->getBody(), $this->maximumFormBytes);
        $this->rejectCredentialParameters($parameters);

        return $parameters;
    }

    /** @return array<string, string> */
    public function parseEncoded(string $encoded, int $maximumBytes): array
    {
        if (strlen($encoded) > $maximumBytes) {
            throw OAuthProtocolException::invalidRequest('OAuth request parameters exceed the supported size.');
        }
        if ($encoded === '') {
            return [];
        }

        $parameters = [];
        $pairs = explode('&', $encoded);
        if (count($pairs) > $this->maximumParameters) {
            throw OAuthProtocolException::invalidRequest('OAuth request contains too many parameters.');
        }

        foreach ($pairs as $pair) {
            if ($pair === '') {
                throw OAuthProtocolException::invalidRequest('OAuth request contains an empty parameter.');
            }
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = $this->decodeComponent($encodedName);
            $value = $this->decodeComponent($encodedValue);
            if (
                $name === ''
                || strlen($name) > $this->maximumNameBytes
                || strlen($value) > $this->maximumValueBytes
                || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $name) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw OAuthProtocolException::invalidRequest('OAuth request contains an invalid parameter.');
            }
            if (array_key_exists($name, $parameters)) {
                throw OAuthProtocolException::invalidRequest('OAuth request contains a duplicate parameter.');
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    private function decodeComponent(string $value): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            throw OAuthProtocolException::invalidRequest('OAuth request contains malformed percent encoding.');
        }

        return rawurldecode(str_replace('+', ' ', $value));
    }

    /** @param array<string, string> $parameters */
    private function rejectCredentialParameters(array $parameters): void
    {
        foreach (self::FORBIDDEN_CREDENTIAL_PARAMETERS as $name) {
            if (array_key_exists($name, $parameters)) {
                throw OAuthProtocolException::invalidRequest('Client credentials must use client_secret_basic.');
            }
        }
    }

    private function validCredential(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && strlen($value) <= $maximumBytes
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
