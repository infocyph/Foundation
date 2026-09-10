<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRequestValidator as EpicryptAuthorizationRequestValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationResult;
use Infocyph\Epicrypt\Auth\OAuth\OAuthErrorCode;
use Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationAudienceResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationClientStore;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;

/**
 * Foundation application-policy facade over Epicrypt's OAuth authorization protocol validator.
 */
final readonly class AuthorizationRequestValidator
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthScopeResolver $scopes,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function redirectContext(array $parameters): AuthorizationRedirectContext
    {
        $result = $this->protocolResult($parameters);
        if ($result->request !== null) {
            return $this->redirectFromAccepted($result);
        }

        $error = $result->error;
        if (!$error instanceof OAuthProtocolError || $error->redirectUri === null) {
            throw $this->protocolException($error);
        }

        $clientId = $parameters['client_id'] ?? null;
        $client = is_string($clientId) ? $this->clients->enabled($clientId) : null;
        if (!$client instanceof OAuthClient) {
            throw $this->protocolException($error);
        }

        return new AuthorizationRedirectContext(
            client: $client,
            redirectUri: $error->redirectUri,
            state: $error->state,
        );
    }

    /** @param array<string, mixed> $parameters */
    public function validate(array $parameters): AuthorizationRequest
    {
        $result = $this->protocolResult($parameters);
        if ($result->request === null) {
            throw $this->protocolException($result->error);
        }

        $protocol = $result->acceptedRequest();
        $client = $this->clients->enabled($protocol->clientId);
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        try {
            $selection = $this->scopes->resolve($client, $protocol->scopes, $protocol->audiences);
        } catch (\InvalidArgumentException) {
            throw OAuthProtocolException::invalidScope(true);
        }

        return new AuthorizationRequest(
            client: $client,
            redirectUri: $protocol->redirectUri,
            codeChallenge: $protocol->codeChallenge,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
            requiredPermissions: $selection->permissions,
            state: $protocol->state,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function protocolResult(array $parameters): OAuthAuthorizationResult
    {
        $validator = new EpicryptAuthorizationRequestValidator(
            new EpicryptOAuthAuthorizationClientStore($this->clients),
            new EpicryptOAuthAuthorizationAudienceResolver($parameters),
        );

        return $validator->validate($parameters);
    }

    private function redirectFromAccepted(OAuthAuthorizationResult $result): AuthorizationRedirectContext
    {
        $request = $result->acceptedRequest();
        $client = $this->clients->enabled($request->clientId);
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        return new AuthorizationRedirectContext(
            client: $client,
            redirectUri: $request->redirectUri,
            state: $request->state,
        );
    }

    private function protocolException(?OAuthProtocolError $error): OAuthProtocolException
    {
        if (!$error instanceof OAuthProtocolError) {
            return OAuthProtocolException::invalidRequest();
        }

        $redirectAllowed = $error->mayRedirect();

        return match ($error->code) {
            OAuthErrorCode::ACCESS_DENIED => OAuthProtocolException::accessDenied(),
            OAuthErrorCode::INVALID_CLIENT => OAuthProtocolException::invalidClient(),
            OAuthErrorCode::INVALID_GRANT => OAuthProtocolException::invalidGrant(),
            OAuthErrorCode::INVALID_SCOPE => OAuthProtocolException::invalidScope($redirectAllowed),
            OAuthErrorCode::UNAUTHORIZED_CLIENT => OAuthProtocolException::unauthorizedClient($redirectAllowed),
            OAuthErrorCode::UNSUPPORTED_GRANT_TYPE => OAuthProtocolException::unsupportedGrantType(),
            OAuthErrorCode::UNSUPPORTED_RESPONSE_TYPE => OAuthProtocolException::unsupportedResponseType($redirectAllowed),
            OAuthErrorCode::SERVER_ERROR => new OAuthProtocolException(
                'server_error',
                'The authorization server could not complete the request.',
                500,
                false,
            ),
            OAuthErrorCode::TEMPORARILY_UNAVAILABLE => new OAuthProtocolException(
                'temporarily_unavailable',
                'The authorization server is temporarily unavailable.',
                503,
                false,
            ),
            OAuthErrorCode::INVALID_DPOP_PROOF => OAuthProtocolException::invalidRequest(),
            OAuthErrorCode::INVALID_REQUEST => OAuthProtocolException::invalidRequest(
                redirectAllowed: $redirectAllowed,
            ),
        };
    }
}
