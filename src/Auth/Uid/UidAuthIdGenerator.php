<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Uid;

use Infocyph\AuthLayer\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

final class UidAuthIdGenerator implements AuthIdGeneratorInterface
{
    public function accountId(): string
    {
        return (string) UUID::v7();
    }

    public function auditEventId(): string
    {
        return (string) UUID::v7();
    }

    public function challengeId(): string
    {
        return (string) UUID::v7();
    }

    public function correlationId(): string
    {
        return (string) ULID::generate();
    }

    public function credentialId(): string
    {
        return (string) UUID::v7();
    }

    public function deviceId(): string
    {
        return (string) UUID::v7();
    }

    public function grantId(): string
    {
        return (string) UUID::v7();
    }

    public function permissionId(): string
    {
        return (string) UUID::v7();
    }

    public function roleId(): string
    {
        return (string) UUID::v7();
    }

    public function sessionId(): string
    {
        return (string) UUID::v7();
    }
}
