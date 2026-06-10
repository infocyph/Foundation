<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\AuthLayer\Authentication\RememberMe\RememberMeManager;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;

final class RememberMePrincipalResolver extends AbstractPrincipalResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RememberMeManager $rememberMe,
        private readonly AccountProviderInterface $accounts,
    ) {}

    public function name(): string
    {
        return 'remember';
    }

    public function resolve(Request $request): ?PrincipalInterface
    {
        $token = $this->rememberToken($request);
        if ($token === null) {
            return null;
        }

        $verified = $this->rememberMe->verify($token, [
            'ip' => $request->ip(true),
            'user_agent' => $request->header('User-Agent'),
        ]);

        if (!$verified->verified() || $verified->record === null) {
            return null;
        }

        $account = $this->accounts->findById($verified->record->accountId);
        if ($account === null) {
            return null;
        }

        return $this->principalForAccount($account, [
            'auth_via' => 'remember',
            'device_id' => $verified->record->deviceId,
            'remember_token_id' => $verified->record->id,
        ]);
    }

    private function rememberToken(Request $request): ?string
    {
        $header = $request->header(
            (string) $this->config->get('auth.http.remember_header', 'X-Remember-Token'),
        );

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $cookie = $request->cookie(
            (string) $this->config->get('auth.http.remember_cookie', 'foundation_remember'),
        );

        return is_string($cookie) && $cookie !== ''
            ? $cookie
            : null;
    }
}
