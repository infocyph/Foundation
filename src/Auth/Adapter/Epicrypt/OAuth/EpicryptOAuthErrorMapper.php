<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Auth\OAuth\OAuthErrorCode;
use Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;

final class EpicryptOAuthErrorMapper
{
    public static function exception(?OAuthProtocolError $error): OAuthProtocolException
    {
        if (!$error instanceof OAuthProtocolError) {
            return OAuthProtocolException::invalidRequest();
        }

        $redirectAllowed = $error->mayRedirect();

        return match ($error->code) {
            OAuthErrorCode::ACCESS_DENIED => OAuthProtocolException::accessDenied(),
            OAuthErrorCode::INVALID_CLIENT => OAuthProtocolException::invalidClient(),
            OAuthErrorCode::INVALID_DPOP_PROOF => OAuthProtocolException::invalidRequest(
                'The DPoP proof is invalid.',
            ),
            OAuthErrorCode::INVALID_GRANT => OAuthProtocolException::invalidGrant(),
            OAuthErrorCode::INVALID_REQUEST => OAuthProtocolException::invalidRequest(
                redirectAllowed: $redirectAllowed,
            ),
            OAuthErrorCode::INVALID_SCOPE => OAuthProtocolException::invalidScope($redirectAllowed),
            OAuthErrorCode::SERVER_ERROR => new OAuthProtocolException(
                'server_error',
                'The authorization server could not complete the request.',
                500,
            ),
            OAuthErrorCode::TEMPORARILY_UNAVAILABLE => new OAuthProtocolException(
                'temporarily_unavailable',
                'The authorization server is temporarily unavailable.',
                503,
            ),
            OAuthErrorCode::UNAUTHORIZED_CLIENT => OAuthProtocolException::unauthorizedClient($redirectAllowed),
            OAuthErrorCode::UNSUPPORTED_GRANT_TYPE => OAuthProtocolException::unsupportedGrantType(),
            OAuthErrorCode::UNSUPPORTED_RESPONSE_TYPE => OAuthProtocolException::unsupportedResponseType($redirectAllowed),
        };
    }
}
