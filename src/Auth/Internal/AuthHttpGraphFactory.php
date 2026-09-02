<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\OAuthBearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\PrincipalResolverInterface;
use Infocyph\Foundation\Http\Resolver\RememberMePrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\SessionPrincipalResolver;

final class AuthHttpGraphFactory
{
    /** @param list<string> $order */
    public static function requestPrincipalResolver(
        ConfigRepository $config,
        SessionPrincipalResolver $session,
        BearerTokenPrincipalResolver $bearer,
        RememberMePrincipalResolver $remember,
        ?OAuthBearerTokenPrincipalResolver $oauth,
        array $order,
    ): RequestPrincipalResolver {
        /** @var array<string, PrincipalResolverInterface> $resolvers */
        $resolvers = [
            'session' => $session,
            'bearer' => $bearer,
            'remember' => $remember,
        ];
        if ($oauth instanceof OAuthBearerTokenPrincipalResolver) {
            $resolvers['oauth_bearer'] = $oauth;
        }

        return new RequestPrincipalResolver(
            config: $config,
            resolvers: $resolvers,
            order: $order,
        );
    }
}
