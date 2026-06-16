<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Id;

interface AuthIdGeneratorInterface
{
    public function accountId(): string;

    public function auditEventId(): string;

    public function challengeId(): string;

    public function correlationId(): string;

    public function credentialId(): string;

    public function deviceId(): string;

    public function grantId(): string;

    public function permissionId(): string;

    public function roleId(): string;

    public function sessionId(): string;
}
