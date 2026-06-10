<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Middleware;

use Infocyph\AuthLayer\Account\AccountStatus;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class VerifiedMiddleware
{
    public function __construct(
        private CurrentPrincipalContext $principals,
        private AccountProviderInterface $accounts,
        private AuthResponseFactory $responses,
    ) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $principal = $this->principals->get();
        $accountId = $principal?->accountId();

        if ($principal === null || $accountId === null || $accountId === '') {
            return $this->responses->unauthorized($request, 'Authentication is required.');
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null || $account->status() === AccountStatus::PENDING_VERIFICATION) {
            return $this->responses->forbidden($request, 'A verified account is required.');
        }

        return $next($request);
    }
}
