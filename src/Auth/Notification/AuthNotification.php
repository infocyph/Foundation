<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Notification;

final readonly class AuthNotification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public AuthNotificationType $type,
        public ?string $accountId,
        public array $payload = [],
    ) {}
}
