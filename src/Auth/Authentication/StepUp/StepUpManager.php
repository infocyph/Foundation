<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\StepUp;

use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class StepUpManager
{
    private const int MAX_STEP_UP_SECONDS = 86400;

    public function __construct(
        private TtlStoreInterface $ttl,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(AuthSession $session, string $ability, array $context = []): StepUpResult
    {
        $method = self::method($context['method'] ?? StepUpMethod::RECENT_AUTH);
        $requirement = new StepUpRequirement(
            ability: $ability,
            maxAgeSeconds: self::duration($context['max_age_seconds'] ?? 900),
            method: $method,
        );
        $now = $this->clock->now();
        if ($session->isExpiredAt($now)) {
            return new StepUpResult(true, $requirement, null, 'step_up_required', $context);
        }

        $satisfiedAt = $this->ttl->get($this->key($session->accountId, $session->id, $ability, $method));
        $satisfiedAt = is_int($satisfiedAt) ? $satisfiedAt : null;
        if ($satisfiedAt !== null && $satisfiedAt <= $now && $satisfiedAt >= $now - $requirement->maxAgeSeconds) {
            return new StepUpResult(false, $requirement, $satisfiedAt, 'step_up_already_satisfied', $context);
        }

        $recent = $method === StepUpMethod::RECENT_AUTH
            && $session->recentAuthAt !== null
            && $session->recentAuthAt <= $now
            && $session->recentAuthAt >= $now - $requirement->maxAgeSeconds;

        return new StepUpResult(!$recent, $requirement, $satisfiedAt, $recent ? 'step_up_not_required' : 'step_up_required', $context);
    }

    public function markSatisfied(string $accountId, string $sessionId, string $ability, StepUpMethod $method = StepUpMethod::RECENT_AUTH, int $ttlSeconds = 900): void
    {
        $this->ttl->put($this->key($accountId, $sessionId, $ability, $method), $this->clock->now(), self::duration($ttlSeconds));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function requiresStepUp(AuthSession $session, string $ability, array $context = []): bool
    {
        return $this->evaluate($session, $ability, $context)->required;
    }

    private static function duration(mixed $seconds): int
    {
        if (!is_int($seconds) || $seconds < 1 || $seconds > self::MAX_STEP_UP_SECONDS) {
            throw new \InvalidArgumentException('Step-up validity must be between 1 and 86400 seconds.');
        }

        return $seconds;
    }

    private static function method(mixed $method): StepUpMethod
    {
        if (is_string($method)) {
            $method = StepUpMethod::tryFrom($method);
        }

        return $method instanceof StepUpMethod
            ? $method
            : throw new \InvalidArgumentException('Unsupported step-up verification method.');
    }

    private function key(string $accountId, string $sessionId, string $ability, StepUpMethod $method): string
    {
        return sprintf('step-up:%s:%s:%s:%s', $accountId, $sessionId, $ability, $method->value);
    }
}
