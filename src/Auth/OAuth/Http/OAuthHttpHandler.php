<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthHttpHandler
{
    public function __construct(
        private OAuthManager $oauth,
        private OAuthHttpInput $input,
        private OAuthHttpResponseFactory $responses,
    ) {}

    public function metadata(): Response
    {
        return $this->responses->metadata($this->oauth->metadata());
    }

    public function jwks(): Response
    {
        return $this->responses->jwks($this->oauth->jwks());
    }

    public function authorization(Request $request): AuthorizationRequest|Response
    {
        try {
            $parameters = $this->input->authorizationQuery($request);
            $redirect = $this->oauth->authorizationRedirectContext($parameters);
        } catch (OAuthProtocolException $exception) {
            return $this->responses->error($exception);
        }

        try {
            return $this->oauth->validateAuthorizationRequest($parameters);
        } catch (OAuthProtocolException $exception) {
            return $this->responses->authorizationError($redirect, $exception, $this->issuer());
        }
    }

    public function authorizationApproved(AuthorizationRequest $request, PrincipalInterface $principal): Response
    {
        $issue = $this->oauth->approve($request, $principal);

        return $this->responses->authorizationSuccess($request, $issue->code, $this->issuer());
    }

    public function authorizationDenied(AuthorizationRequest $request): Response
    {
        return $this->responses->authorizationError(
            new AuthorizationRedirectContext($request->client, $request->redirectUri, $request->state),
            OAuthProtocolException::accessDenied(),
            $this->issuer(),
        );
    }

    public function token(Request $request): Response
    {
        try {
            $parameters = $this->input->form($request);
            $authentication = $this->input->clientAuthentication($request, $parameters);

            return $this->responses->token($this->oauth->exchange($parameters, $authentication));
        } catch (OAuthProtocolException $exception) {
            return $this->responses->error($exception);
        }
    }

    public function revocation(Request $request): Response
    {
        try {
            $parameters = $this->input->form($request);
            $authentication = $this->input->clientAuthentication($request, $parameters);
            $this->oauth->revoke(
                $this->requiredString($parameters, 'token', 4096),
                $authentication,
                $this->optionalString($parameters, 'token_type_hint', 64),
            );

            return $this->responses->revocation();
        } catch (OAuthProtocolException $exception) {
            return $this->responses->error($exception);
        }
    }

    public function introspection(Request $request): Response
    {
        try {
            $parameters = $this->input->form($request);
            $authentication = $this->input->clientAuthentication($request, $parameters);
            $result = $this->oauth->introspect(
                $this->requiredString($parameters, 'token', 4096),
                $authentication,
            );

            return $this->responses->introspection($result);
        } catch (OAuthProtocolException $exception) {
            return $this->responses->error($exception);
        }
    }

    /** @param array<string, string> $parameters */
    private function requiredString(array $parameters, string $name, int $maximumBytes): string
    {
        $value = $parameters[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes) {
            throw OAuthProtocolException::invalidRequest();
        }

        return $value;
    }

    /** @param array<string, string> $parameters */
    private function optionalString(array $parameters, string $name, int $maximumBytes): ?string
    {
        if (!array_key_exists($name, $parameters)) {
            return null;
        }

        return $this->requiredString($parameters, $name, $maximumBytes);
    }

    private function issuer(): string
    {
        $issuer = $this->oauth->metadata()['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new \LogicException('OAuth authorization-server metadata does not expose a valid issuer.');
        }

        return $issuer;
    }
}
