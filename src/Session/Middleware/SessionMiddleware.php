<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Middleware;

use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Response;

final readonly class SessionMiddleware
{
    public function __construct(
        private SessionManager $sessions,
        private SessionConfig $config,
    ) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $session = $this->sessions->open($request->getCookieParams()[$this->config->cookieName] ?? null);
        $this->sessions->enter($session);

        try {
            $response = $next($request->withAttribute(BrowserSession::REQUEST_ATTRIBUTE, $session));
            $commit = $session->commit(time());
            if (!$commit->persisted || $commit->id === null) {
                return $response;
            }

            return $response->withAddedHeader('Set-Cookie', (string) $this->cookie($commit->id));
        } finally {
            try {
                $session->release();
            } finally {
                $this->sessions->leave($session);
            }
        }
    }

    private function cookie(string $id): Cookie
    {
        $cookie = Cookie::make($this->config->cookieName, $id)
            ->path($this->config->cookiePath)
            ->secure($this->config->cookieSecure)
            ->httpOnly($this->config->cookieHttpOnly)
            ->sameSite($this->config->cookieSameSite)
            ->maxAge($this->config->lifetimeSeconds);

        return $this->config->cookieDomain !== null
            ? $cookie->domain($this->config->cookieDomain)
            : $cookie;
    }
}
