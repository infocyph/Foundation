<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthAuthorizationController
{
    public function __construct(
        private OAuthHttpHandler $oauth,
        private CurrentPrincipalContext $principals,
        private SessionConfig $session,
    ) {}

    public function authorization(Request $request): Response
    {
        $authorization = $this->oauth->authorization($request);
        if ($authorization instanceof Response) {
            return $authorization;
        }

        if ($request->getEffectiveMethod() === 'GET') {
            return $this->consent($request, $authorization);
        }

        return match ($request->post('decision')) {
            'approve' => $this->oauth->authorizationApproved($authorization, $this->principals->require()),
            'deny' => $this->oauth->authorizationDenied($authorization, $this->principals->require()),
            default => $this->consent(
                $request,
                $authorization,
                'Choose whether to approve or deny this request.',
            ),
        };
    }

    private function consent(
        Request $request,
        AuthorizationRequest $authorization,
        ?string $error = null,
    ): Response {
        $uri = $request->getUri();
        $action = $uri->getPath();
        if ($uri->getQuery() !== '') {
            $action .= '?' . $uri->getQuery();
        }

        return $this->consentPage(
            $authorization,
            $action,
            $this->session->csrfField,
            BrowserSession::fromRequest($request)->csrfToken(),
            $error,
        );
    }

    private function consentPage(
        AuthorizationRequest $authorization,
        string $action,
        string $csrfField,
        string $csrfToken,
        ?string $error,
    ): Response {
        $clientId = $this->escape($authorization->client->clientId);
        $action = $this->escape($action);
        $csrfField = $this->escape($csrfField);
        $csrfToken = $this->escape($csrfToken);
        $scopes = $this->items($authorization->scopes, 'No scopes requested.');
        $audiences = $this->items($authorization->audiences, 'No resource audience requested.');
        $error = $error === null ? '' : '<p role="alert">' . $this->escape($error) . '</p>';

        $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Authorize {$clientId}</title>
        </head>
        <body>
            <main>
                <h1>Authorize {$clientId}</h1>
                {$error}
                <p>This application is requesting access to:</p>
                <h2>Scopes</h2>
                {$scopes}
                <h2>Resources</h2>
                {$audiences}
                <form method="post" action="{$action}">
                    <input type="hidden" name="{$csrfField}" value="{$csrfToken}">
                    <button type="submit" name="decision" value="approve">Approve</button>
                    <button type="submit" name="decision" value="deny">Deny</button>
                </form>
            </main>
        </body>
        </html>
        HTML;

        return Response::create($html, $error === '' ? 200 : 422, [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'Content-Type' => 'text/html; charset=utf-8',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<string> $values
     */
    private function items(array $values, string $empty): string
    {
        if ($values === []) {
            return '<p>' . $this->escape($empty) . '</p>';
        }

        return '<ul><li>'
            . implode('</li><li>', array_map($this->escape(...), $values))
            . '</li></ul>';
    }
}
