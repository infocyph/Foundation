<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Http;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class OAuthRateLimitMiddleware
{
    public function __construct(
        private string $endpoint,
        private ThrottleMiddleware $throttle,
        private OAuthAuditRecorder $audit,
    ) {
        if ($this->endpoint === '') {
            throw new \InvalidArgumentException('OAuth rate-limit endpoint cannot be empty.');
        }
    }

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, callable $next): Response
    {
        try {
            return ($this->throttle)($request, $next(...));
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 429) {
                $this->audit->record(
                    AuthEventType::OAUTH_RATE_LIMITED,
                    metadata: [
                        'reason' => $this->endpoint,
                        'result' => 'rejected',
                    ],
                    severity: AuthEventSeverity::WARNING,
                );
            }

            throw $exception;
        }
    }
}
