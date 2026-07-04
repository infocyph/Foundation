<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Response;

use Infocyph\Foundation\Auth\Exception\AuthenticationException;
use Infocyph\Foundation\Auth\Exception\AuthorizationException;
use Infocyph\Foundation\Auth\Exception\LockoutException;
use Infocyph\Foundation\Auth\Exception\MfaException;
use Infocyph\Foundation\Auth\Exception\PasskeyException;
use Infocyph\Foundation\Auth\Exception\SessionException;
use Infocyph\Foundation\Auth\Exception\TokenAuthException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class AuthExceptionMapper
{
    /**
     * @var array<class-string, int>
     */
    private array $map;

    /**
     * @param array<class-string, int>|null $map
     */
    public function __construct(
        private AuthResponseFactory $responses,
        ?array $map = null,
    ) {
        $this->map = $map ?? [
            AuthenticationException::class => 401,
            AuthorizationException::class => 403,
            SessionException::class => 401,
            TokenAuthException::class => 401,
            MfaException::class => 403,
            PasskeyException::class => 403,
            LockoutException::class => 423,
        ];
    }

    public function supports(\Throwable $exception): bool
    {
        return array_any(
            array_keys($this->map),
            static fn(string $class): bool => $exception instanceof $class,
        );
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
