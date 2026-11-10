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

    #[\Override]
    public function record(AuthEvent $event): void
    {
        $this->events[] = $event;
    }

    public function recorder(int $now = 1_700_000_000): OAuthAuditRecorder
    {
        $ids = new class implements AuthIdGeneratorInterface {
            private int $sequence = 0;

            #[\Override]
            public function accountId(): string { return $this->next('account'); }
            #[\Override]
            public function auditEventId(): string { return $this->next('audit'); }
            #[\Override]
            public function challengeId(): string { return $this->next('challenge'); }
            #[\Override]
            public function correlationId(): string { return $this->next('correlation'); }
            #[\Override]
            public function credentialId(): string { return $this->next('credential'); }
            #[\Override]
            public function deviceId(): string { return $this->next('device'); }
            #[\Override]
            public function grantId(): string { return $this->next('grant'); }
            #[\Override]
            public function permissionId(): string { return $this->next('permission'); }
            #[\Override]
            public function roleId(): string { return $this->next('role'); }
            #[\Override]
            public function sessionId(): string { return $this->next('session'); }

            private function next(string $prefix): string
            {
                return sprintf('%s-%d', $prefix, ++$this->sequence);
            }
        };
        $clock = new readonly class($now) implements ClockInterface {
            public function __construct(private int $time) {}
            #[\Override]
            public function now(): int { return $this->time; }
        };

        return new OAuthAuditRecorder($this, $ids, $clock);
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
