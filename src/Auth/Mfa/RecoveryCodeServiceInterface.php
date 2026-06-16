<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface RecoveryCodeServiceInterface
{
    /**
     * @return list<string>
     */
    public function generate(string $accountId, int $count = 10): array;

    public function verify(string $accountId, string $code): RecoveryCodeVerificationResult;
}
