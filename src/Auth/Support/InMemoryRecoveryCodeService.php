<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\AuthLayer\Mfa\RecoveryCodeServiceInterface;
use Infocyph\AuthLayer\Mfa\RecoveryCodeVerificationResult;

final class InMemoryRecoveryCodeService implements RecoveryCodeServiceInterface
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $codes = [];

    public function generate(string $accountId, int $count = 10): array
    {
        $plain = [];

        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
            $plain[] = $code;
            $this->codes[$accountId][$code] = hash('sha256', $code);
        }

        return $plain;
    }

    public function verify(string $accountId, string $code): RecoveryCodeVerificationResult
    {
        $stored = $this->codes[$accountId][$code] ?? null;
        if ($stored === null) {
            return new RecoveryCodeVerificationResult(false, 'recovery_code_invalid');
        }

        if (!hash_equals($stored, hash('sha256', $code))) {
            return new RecoveryCodeVerificationResult(false, 'recovery_code_invalid');
        }

        unset($this->codes[$accountId][$code]);

        return new RecoveryCodeVerificationResult(true);
    }
}
