<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;

final class RandomAuthIdGenerator implements AuthIdGeneratorInterface
{
    public function accountId(): string
    {
        return $this->generate('acct');
    }

    public function auditEventId(): string
    {
        return $this->generate('evt');
    }

    public function challengeId(): string
    {
        return $this->generate('chl');
    }

    public function correlationId(): string
    {
        return $this->generate('corr');
    }

    public function credentialId(): string
    {
        return $this->generate('cred');
    }

    public function deviceId(): string
    {
        return $this->generate('dev');
    }

    public function grantId(): string
    {
        return $this->generate('grant');
    }

    public function permissionId(): string
    {
        return $this->generate('perm');
    }

    public function roleId(): string
    {
        return $this->generate('role');
    }

    public function sessionId(): string
    {
        return $this->generate('sess');
    }

    private function generate(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(16)));
    }
}
