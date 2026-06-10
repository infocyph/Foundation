<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class AuthExceptionMapper
{
    /**
     * @var array<class-string, int>
     */
    private array $map;

    public function __construct(
        private AuthResponseFactory $responses,
        ?array $map = null,
    ) {
        $this->map = $map ?? [
            'Infocyph\AuthLayer\Exception\AuthenticationException' => 401,
            'Infocyph\AuthLayer\Exception\AuthorizationException' => 403,
            'Infocyph\AuthLayer\Exception\SessionException' => 401,
            'Infocyph\AuthLayer\Exception\TokenAuthException' => 401,
            'Infocyph\AuthLayer\Exception\MfaException' => 403,
            'Infocyph\AuthLayer\Exception\PasskeyException' => 403,
            'Infocyph\AuthLayer\Exception\LockoutException' => 423,
        ];
    }

    public function supports(\Throwable $exception): bool
    {
        foreach (array_keys($this->map) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function toResponse(Request $request, \Throwable $exception): Response
    {
        $status = $this->supports($exception) ? 403 : 500;

        foreach ($this->map as $class => $candidate) {
            if ($exception instanceof $class) {
                $status = $candidate;
                break;
            }
        }

        return match ($status) {
            401 => $this->responses->unauthorized($request, $this->message($exception, 'Authentication failed.')),
            423 => $this->responses->locked($request, $this->message($exception, 'Access is temporarily locked.')),
            429 => $this->responses->tooManyRequests($request, $this->message($exception, 'Too many authentication attempts.')),
            500 => $this->responses->serverError($request, $this->message($exception, 'An unexpected error occurred.')),
            default => $this->responses->forbidden($request, $this->message($exception, 'Authorization failed.')),
        };
    }

    private function message(\Throwable $exception, string $fallback): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : $fallback;
    }
}
