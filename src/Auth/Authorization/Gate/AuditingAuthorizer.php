<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Gate;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Exception\AuthorizationException;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class AuditingAuthorizer implements AuthorizerInterface
{
    public function __construct(
        private AuthorizerInterface $inner,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void
    {
        $decision = $this->can($principal, $ability, $resource, $context);

        if (!$decision->allowed) {
            throw new AuthorizationException(
                $decision->reason ?? 'Authorization failed.',
                $decision->code,
            );
        }
    }

    public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision
    {
        $decision = $this->inner->can($principal, $ability, $resource, $context);

        if (!$decision->allowed) {
            AuthEventRecorder::record(
                $this->audit,
                $this->ids,
                $this->clock,
                AuthEventType::AUTHORIZATION_DENIED,
                $principal->accountId(),
                actorId: $principal->id(),
                metadata: ['ability' => $ability, 'code' => $decision->code, 'reason' => $decision->reason] + $context,
                severity: AuthEventSeverity::WARNING,
                sessionId: ContextValue::stringOrNull($context, 'session_id'),
                deviceId: ContextValue::stringOrNull($context, 'device_id'),
            );
        }

        return $decision;
    }
}
