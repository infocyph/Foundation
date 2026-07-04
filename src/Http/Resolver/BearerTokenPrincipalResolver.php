<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;

final class BearerTokenPrincipalResolver extends AbstractPrincipalResolver
{
    public function __construct(
        ConfigRepository $config,
        private readonly AccessTokenServiceInterface $tokens,
        private readonly AccountProviderInterface $accounts,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'bearer';
    }

    public function resolve(Request $request): ?PrincipalInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return null;
        }

        $verified = $this->tokens->verify($token);
        if (!$verified->verified || $verified->subjectId === null || $verified->subjectId === '') {
            return null;
        }

        $account = $this->accounts->findById($verified->subjectId);
        if ($account === null) {
            return null;
        }

        return $this->principalForAccount($account, [
            'auth_via' => 'bearer',
            'claims' => $verified->claims,
            'token_id' => $verified->tokenId,
        ]);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $this->headerValue($request, 'auth.http.bearer_header', 'Authorization');
        if ($header === null) {
            return null;
        }

        $prefix = $this->stringConfig('auth.http.bearer_prefix', 'Bearer ');
        if ($prefix !== '' && strncasecmp($header, $prefix, strlen($prefix)) === 0) {
            $token = trim(substr($header, strlen($prefix)));

            return $token !== '' ? $token : null;
        }

        return null;
    }
}
