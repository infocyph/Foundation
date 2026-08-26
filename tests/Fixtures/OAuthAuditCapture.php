<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Audit\AuthEvent;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;

final class OAuthAuditCapture implements AuditEventStoreInterface
{
    /** @var list<AuthEvent> */
    public array $events = [];

    public function record(AuthEvent $event): void
    {
        $this->events[] = $event;
    }

    public function recorder(int $now = 1_700_000_000): OAuthAuditRecorder
    {
        $ids = new class implements AuthIdGeneratorInterface {
            private int $sequence = 0;

            public function accountId(): string { return $this->next('account'); }
            public function auditEventId(): string { return $this->next('audit'); }
            public function challengeId(): string { return $this->next('challenge'); }
            public function correlationId(): string { return $this->next('correlation'); }
            public function credentialId(): string { return $this->next('credential'); }
            public function deviceId(): string { return $this->next('device'); }
            public function grantId(): string { return $this->next('grant'); }
            public function permissionId(): string { return $this->next('permission'); }
            public function roleId(): string { return $this->next('role'); }
            public function sessionId(): string { return $this->next('session'); }

            private function next(string $prefix): string
            {
                return sprintf('%s-%d', $prefix, ++$this->sequence);
            }
        };
        $clock = new readonly class($now) implements ClockInterface {
            public function __construct(private int $time) {}
            public function now(): int { return $this->time; }
        };

        return new OAuthAuditRecorder($this, $ids, $clock);
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
