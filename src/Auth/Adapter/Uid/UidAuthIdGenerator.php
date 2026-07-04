<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Uid;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

final class UidAuthIdGenerator implements AuthIdGeneratorInterface
{
    public function accountId(): string
    {
        return UUID::v7();
    }

    public function auditEventId(): string
    {
        return UUID::v7();
    }

    public function challengeId(): string
    {
        return UUID::v7();
    }

    public function correlationId(): string
    {
        return ULID::generate();
    }

    public function credentialId(): string
    {
        return UUID::v7();
    }

    public function deviceId(): string
    {
        return UUID::v7();
    }

    public function grantId(): string
    {
        return UUID::v7();
    }

    public function permissionId(): string
    {
        return UUID::v7();
    }

    public function roleId(): string
    {
        return UUID::v7();
    }

    public function sessionId(): string
    {
        return UUID::v7();
    }
}
