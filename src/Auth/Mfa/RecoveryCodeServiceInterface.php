<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface RecoveryCodeServiceInterface
{
    /**
     * A non-positive count selects the configured/provider default.
     *
     * @return list<string>
     */
    public function generate(string $accountId, int $count = 0): array;

    public function verify(string $accountId, string $code): RecoveryCodeVerificationResult;
}
