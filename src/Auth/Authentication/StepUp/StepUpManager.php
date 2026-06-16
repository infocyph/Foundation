<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\StepUp;

use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class StepUpManager
{
    public function __construct(
        private TtlStoreInterface $ttl,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(AuthSession $session, string $ability, array $context = []): StepUpResult
    {
        $method = $context['method'] ?? StepUpMethod::RECENT_AUTH;

        if (is_string($method)) {
            $method = StepUpMethod::tryFrom($method) ?? StepUpMethod::RECENT_AUTH;
        } elseif (!$method instanceof StepUpMethod) {
            $method = StepUpMethod::RECENT_AUTH;
        }

        $requirement = new StepUpRequirement(
            ability: $ability,
            maxAgeSeconds: ContextValue::int($context, 'max_age_seconds', 900),
            method: $method,
        );

        $satisfiedAt = $this->ttl->get($this->key($session->accountId, $session->id, $ability, $method));
        $satisfiedAt = is_int($satisfiedAt) ? $satisfiedAt : null;

        if (is_int($satisfiedAt) && $satisfiedAt >= ($this->clock->now() - $requirement->maxAgeSeconds)) {
            return new StepUpResult(false, $requirement, $satisfiedAt, 'step_up_already_satisfied', $context);
        }

        $required = $session->recentAuthAt === null || $session->recentAuthAt < ($this->clock->now() - $requirement->maxAgeSeconds);

        return new StepUpResult($required, $requirement, $satisfiedAt, $required ? 'step_up_required' : 'step_up_not_required', $context);
    }

    public function markSatisfied(string $accountId, string $sessionId, string $ability, StepUpMethod $method = StepUpMethod::RECENT_AUTH, int $ttlSeconds = 900): void
    {
        $this->ttl->put($this->key($accountId, $sessionId, $ability, $method), $this->clock->now(), $ttlSeconds);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function requiresStepUp(AuthSession $session, string $ability, array $context = []): bool
    {
        return $this->evaluate($session, $ability, $context)->required;
    }

    private function key(string $accountId, string $sessionId, string $ability, StepUpMethod $method): string
    {
        return sprintf('step-up:%s:%s:%s:%s', $accountId, $sessionId, $ability, $method->value);
    }
}
