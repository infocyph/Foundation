<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRequestValidator as EpicryptAuthorizationRequestValidator;
use Infocyph\Epicrypt\Auth\OAuth\OAuthErrorCode;
use Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdAuthorizationRequestValidator;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationAudienceResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAuthorizationClientStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthErrorMapper;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Config\ConfigRepository;

/**
 * Foundation application-policy facade over Epicrypt's OAuth/OIDC authorization validators.
 */
final readonly class AuthorizationRequestValidator
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthScopeResolver $scopes,
        private ConfigRepository $config,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function evaluate(array $parameters): AuthorizationProtocolResult
    {
        return $this->protocolResult($parameters);
    }

    /** @param array<string, mixed> $parameters */
    public function redirectContext(array $parameters): AuthorizationRedirectContext
    {
        return $this->redirectContextFor($parameters, $this->protocolResult($parameters));
    }

    /** @param array<string, mixed> $parameters */
    public function redirectContextFor(
        array $parameters,
        AuthorizationProtocolResult $result,
    ): AuthorizationRedirectContext {
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
        return $this->validateResult($this->protocolResult($parameters));
    }

    public function validateResult(AuthorizationProtocolResult $result): AuthorizationRequest
    {
        if ($result->request === null) {
            throw $this->protocolException($result->error);
        }

        $protocol = $result->request;
        $client = $this->clients->enabled($protocol->clientId);
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        try {
            $selection = $this->scopes->resolve($client, $protocol->scopes, $protocol->audiences);
        } catch (\InvalidArgumentException) {
            throw OAuthProtocolException::invalidScope(true);
        }

        $openId = $result->openId;

        return new AuthorizationRequest(
            client: $client,
            redirectUri: $protocol->redirectUri,
            codeChallenge: $protocol->codeChallenge,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
            requiredPermissions: $selection->permissions,
            state: $protocol->state,
            openIdNonce: $openId?->nonce,
            openIdPrompts: $openId === null
                ? []
                : array_map(static fn($prompt): string => $prompt->value, $openId->prompts),
            openIdMaximumAuthenticationAge: $openId?->maximumAuthenticationAge,
            openIdAcrValues: $openId === null ? [] : $openId->acrValues,
            openIdProtocol: $openId,
        );
    }

    private function protocolException(?OAuthProtocolError $error): OAuthProtocolException
    {
        return EpicryptOAuthErrorMapper::exception($error);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, string|list<string>>
     */
    private function protocolParameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $name => $value) {
            if (is_string($value)) {
                $normalized[$name] = $value;

                continue;
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw OAuthProtocolException::invalidRequest();
            }
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw OAuthProtocolException::invalidRequest();
                }
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $parameters */
    private function protocolResult(array $parameters): AuthorizationProtocolResult
    {
        $protocolParameters = $this->protocolParameters($parameters);
        $oauth = new EpicryptAuthorizationRequestValidator(
            new EpicryptOAuthAuthorizationClientStore($this->clients),
            new EpicryptOAuthAuthorizationAudienceResolver($protocolParameters),
        );

        if ($this->config->get('auth.oauth.oidc.enabled', false) === true) {
            $result = new OpenIdAuthorizationRequestValidator($oauth)->validate($protocolParameters);

            return new AuthorizationProtocolResult(
                $result->oauthRequest,
                $result->openIdRequest,
                $result->error,
            );
        }

        $result = $oauth->validate($protocolParameters);
        if ($result->request !== null && in_array('openid', $result->request->scopes, true)) {
            return new AuthorizationProtocolResult(
                null,
                null,
                new OAuthProtocolError(
                    OAuthErrorCode::INVALID_SCOPE,
                    $result->request->redirectUri,
                    $result->request->state,
                ),
            );
        }

        return new AuthorizationProtocolResult($result->request, null, $result->error);
    }

    private function redirectFromAccepted(AuthorizationProtocolResult $result): AuthorizationRedirectContext
    {
        $request = $result->request
            ?? throw new \LogicException('Accepted authorization result has no request.');
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
}
