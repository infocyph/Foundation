<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

trait GeneratesAuthIds
{
    abstract protected function generate(string $key): string;

    public function accountId(): string
    {
        return $this->generate('account');
    }

    public function auditEventId(): string
    {
        return $this->generate('audit_event');
    }

    public function challengeId(): string
    {
        return $this->generate('challenge');
    }

    public function correlationId(): string
    {
        return $this->generate('correlation');
    }

    public function credentialId(): string
    {
        return $this->generate('credential');
    }

    public function deviceId(): string
    {
        return $this->generate('device');
    }

    public function grantId(): string
    {
        return $this->generate('grant');
    }

    public function permissionId(): string
    {
        return $this->generate('permission');
    }

    public function roleId(): string
    {
        return $this->generate('role');
    }

    public function sessionId(): string
    {
        return $this->generate('session');
    }
}
