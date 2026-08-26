<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthVerifiedAccessToken;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Request\Request;

final class OAuthBearerTokenPrincipalResolver extends AbstractPrincipalResolver
{
    /** @var list<string> */
    private readonly array $audiences;

    private readonly string $header;

    private readonly string $prefix;

    public function __construct(
        ConfigRepository $config,
        private readonly OAuthAccessTokenValidator $validator,
    ) {
        parent::__construct($config);
        $this->header = $this->stringConfig('auth.http.bearer_header', 'Authorization');
        $this->prefix = $this->stringConfig('auth.http.bearer_prefix', 'Bearer ');
        $this->audiences = ValueNormalizer::stringList($config->get('auth.oauth.resource_audiences', []));
    }

    public function name(): string
    {
        return 'oauth_bearer';
    }

    public function resolve(Request $request): ?PrincipalInterface
    {
        if ($this->audiences === []) {
            return null;
        }

        $token = $this->bearerToken($request);
        if ($token === null) {
            return null;
        }

        $verified = $this->verifyForAnyAudience($token);
        if (!$verified instanceof OAuthVerifiedAccessToken) {
            return null;
        }

        $metadata = [
            'auth_via' => 'oauth_bearer',
            'oauth_token_id' => $verified->claims->tokenId,
            'oauth_client_id' => $verified->claims->clientId,
            'oauth_authorization_id' => $verified->claims->authorizationId,
            'oauth_scopes' => $verified->claims->scopes,
            'oauth_audiences' => $verified->claims->audiences,
            'oauth_expires_at' => $verified->claims->expiresAt,
        ];

        if ($verified->servicePrincipal()) {
            return new Principal(
                id: $verified->client->clientId,
                type: PrincipalType::SERVICE,
                metadata: $metadata,
            );
        }

        return $verified->account === null
            ? null
            : $this->principalForAccount($verified->account, $metadata);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header($this->header);
        if (!is_string($header) || $header === '') {
            return null;
        }
        if ($this->prefix !== '' && strncasecmp($header, $this->prefix, strlen($this->prefix)) === 0) {
            $token = trim(substr($header, strlen($this->prefix)));

            return $token !== '' && strlen($token) <= 8192 ? $token : null;
        }

        return null;
    }

    private function verifyForAnyAudience(#[\SensitiveParameter] string $token): ?OAuthVerifiedAccessToken
    {
        foreach ($this->audiences as $audience) {
            try {
                return $this->validator->verify($token, $audience);
            } catch (OAuthTokenException) {
                continue;
            }
        }

        return null;
    }
}
