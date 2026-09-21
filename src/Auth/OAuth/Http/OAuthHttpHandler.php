<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthHttpHandler
{
    public function __construct(
        private OAuthManager $oauth,
        private OAuthHttpInput $input,
        private OAuthHttpResponseFactory $responses,
        private ConfigRepository $config,
    ) {}

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

    public function authorizationDenied(AuthorizationRequest $request, ?PrincipalInterface $principal = null): Response
    {
        $this->oauth->deny($request, $principal);

        return $this->responses->authorizationError(
            new AuthorizationRedirectContext($request->client, $request->redirectUri, $request->state),
            OAuthProtocolException::accessDenied(),
            $this->issuer(),
        );
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

    public function jwks(): Response
    {
        return $this->responses->jwks($this->oauth->jwks());
    }

    public function metadata(): Response
    {
        return $this->responses->metadata($this->oauth->metadata());
    }

    public function openIdMetadata(): Response
    {
        return $this->responses->metadata($this->oauth->openIdMetadata());
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

    public function userInfo(Request $request): Response
    {
        try {
            $token = $this->resourceToken($request);
            $uri = $this->openIdUserInfoUri();

            return $this->responses->userInfo($this->oauth->userInfo(
                $token,
                $this->openIdUserInfoAudience($uri),
                $request->getEffectiveMethod(),
                $uri,
                $this->dpopProof($request),
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->responses->userInfoError($exception);
        }
    }

    public function token(Request $request): Response
    {
        try {
            $parameters = $this->input->form($request);
            $authentication = $this->input->clientAuthentication($request, $parameters);

            return $this->responses->token($this->oauth->exchange(
                $parameters,
                $authentication,
                $this->dpopProof($request),
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->responses->error($exception);
        }
    }

    private function dpopProof(Request $request): ?string
    {
        $values = $request->getHeader('DPoP');
        if (count($values) > 1) {
            throw OAuthProtocolException::invalidRequest('The DPoP proof is invalid.');
        }

        $proof = $values[0] ?? '';
        if ($proof === '') {
            return null;
        }
        if (strlen($proof) > 16_384 || preg_match('/[\x00-\x20\x7F]/', $proof) === 1) {
            throw OAuthProtocolException::invalidRequest('The DPoP proof is invalid.');
        }

        return $proof;
    }

    private function openIdUserInfoAudience(string $fallback): string
    {
        $audience = $this->config->get('auth.oauth.oidc.userinfo_audience');

        return is_string($audience) && $audience !== '' ? $audience : $fallback;
    }

    private function openIdUserInfoUri(): string
    {
        $issuer = $this->oauth->metadata()['issuer'] ?? null;
        $route = $this->config->get('auth.oauth.oidc.userinfo_route');
        if (!is_string($issuer) || !is_string($route) || $issuer === '' || !str_starts_with($route, '/')) {
            throw new \LogicException('OpenID UserInfo endpoint configuration is invalid.');
        }

        $parts = parse_url($issuer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('OpenID issuer configuration is invalid.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . $route;
    }

    private function resourceToken(Request $request): string
    {
        $values = $request->getHeader('Authorization');
        if (count($values) !== 1
            || preg_match('/\A(?:Bearer|DPoP)[ \t]+([^ \t]+)\z/iD', $values[0], $match) !== 1
            || strlen($match[1]) > 16_384
            || preg_match('/[\x00-\x20\x7F]/', $match[1]) === 1) {
            throw new OAuthProtocolException('invalid_token', 'The access token is invalid.', 401);
        }

        return $match[1];
    }

    private function issuer(): string
    {
        $issuer = $this->oauth->metadata()['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new \LogicException('OAuth authorization-server metadata does not expose a valid issuer.');
        }

        return $issuer;
    }

    /** @param array<string, string> $parameters */
    private function optionalString(array $parameters, string $name, int $maximumBytes): ?string
    {
        if (!array_key_exists($name, $parameters)) {
            return null;
        }

        return $this->requiredString($parameters, $name, $maximumBytes);
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
}
