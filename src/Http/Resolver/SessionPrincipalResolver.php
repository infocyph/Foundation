<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;
use Psr\Container\ContainerInterface;

final class SessionPrincipalResolver extends AbstractPrincipalResolver
{
    private readonly string $cookie;

    private readonly string $header;

    public function __construct(ConfigRepository $config, ContainerInterface $container)
    {
        parent::__construct($config, $container);
        $this->header = $this->stringConfig('auth.http.session_header', 'X-Session-Id');
        $this->cookie = $this->stringConfig('auth.http.session_cookie', 'foundation_session');
    }

    public function name(): string
    {
        return 'session';
    }

    public function resolve(Request $request): ?PrincipalInterface
    {
        $sessionId = $this->sessionId($request);
        if ($sessionId === null) {
            return null;
        }

        $session = $this->service(SessionStoreInterface::class)->find($sessionId);
        if ($session === null) {
            return null;
        }

        $now = $this->service(ClockInterface::class)->now();
        if ($session->expiresAt <= $now) {
            $this->service(SessionStoreInterface::class)->revoke($sessionId);

            return null;
        }

        $account = $this->service(AccountProviderInterface::class)->findById($session->accountId);
        if ($account === null) {
            return null;
        }

        $principal = $this->principalForAccount($account, [
            'auth_via' => 'session',
            'device_id' => $session->deviceId,
            'session_id' => $session->id,
        ]);
        if ($principal === null) {
            return null;
        }

        $this->service(SessionStoreInterface::class)->touch($sessionId, $now);

        return $principal;
    }

    private function sessionId(Request $request): ?string
    {
        $header = $request->header($this->header);
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $cookie = $request->cookie($this->cookie);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
