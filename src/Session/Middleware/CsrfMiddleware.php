<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Middleware;

use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class CsrfMiddleware
{
    /** @var array<string, true> */
    private const array SAFE_METHODS = [
        'GET' => true,
        'HEAD' => true,
        'OPTIONS' => true,
        'TRACE' => true,
    ];

    public function __construct(private SessionConfig $config) {}

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        if (isset(self::SAFE_METHODS[$request->getEffectiveMethod()])) {
            return $next($request);
        }

        if (!$this->validOrigin($request)) {
            return $this->rejected();
        }

        $provided = $request->getHeaderLine($this->config->csrfHeader);
        if ($provided === '') {
            $body = $request->getParsedBody();
            $value = is_array($body) ? ($body[$this->config->csrfField] ?? null) : null;
            $provided = is_string($value) ? $value : '';
        }

        $expected = BrowserSession::fromRequest($request)->csrfToken();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->rejected();
        }

        return $next($request);
    }

    private function rejected(): Response
    {
        return Response::json(
            ['message' => 'CSRF token mismatch.'],
            419,
            ['Cache-Control' => 'no-store'],
        );
    }

    private function validOrigin(Request $request): bool
    {
        if (!$this->config->csrfCheckOrigin) {
            return true;
        }

        $origin = rtrim($request->getHeaderLine('Origin'), '/');
        if ($origin === '') {
            return true;
        }

        $expected = $this->config->csrfOrigin;
        if ($expected === null) {
            $uri = $request->getUri();
            $expected = $uri->getScheme() . '://' . $uri->getHost();
            if ($uri->getPort() !== null) {
                $expected .= ':' . $uri->getPort();
            }
        }

        return hash_equals(rtrim($expected, '/'), $origin);
    }
}
