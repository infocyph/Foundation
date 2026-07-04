<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Request\Request;

abstract class AbstractPrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(
        protected readonly ConfigRepository $config,
    ) {}

    protected function headerOrCookieValue(
        Request $request,
        string $headerKey,
        string $headerDefault,
        string $cookieKey,
        string $cookieDefault,
    ): ?string {
        $header = $this->headerValue($request, $headerKey, $headerDefault);
        if ($header !== null) {
            return $header;
        }

        $cookie = $request->cookie($this->stringConfig($cookieKey, $cookieDefault));

        return is_string($cookie) && $cookie !== ''
            ? $cookie
            : null;
    }

    protected function headerValue(Request $request, string $key, string $default): ?string
    {
        $value = $request->header($this->stringConfig($key, $default));

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function principalForAccount(AccountInterface $account, array $metadata = []): ?PrincipalInterface
    {
        if (in_array($account->status(), [
            AccountStatus::DISABLED,
            AccountStatus::LOCKED,
            AccountStatus::SUSPENDED,
        ], true)) {
            return null;
        }

        return new Principal(
            id: $account->id(),
            type: PrincipalType::ACCOUNT,
            accountId: $account->id(),
            metadata: $metadata,
        );
    }

    protected function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
