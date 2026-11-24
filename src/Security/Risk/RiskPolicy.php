<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

/**
 * Compiled, deterministic risk policy. All evidence is supplied by the host,
 * not derived from raw authorization headers or user claims.
 *
 * @api
 */
final readonly class RiskPolicy
{
    /** @var array<string,list<string>> */
    private array $agentMarkers;

    /**
     * Map an attested JA4 to operator-qualified User-Agent markers.
     * Absence of a mapping is not evidence of inconsistency.
     *
     * @param array<array-key,mixed> $agentMarkers
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $agentMarkers = [],
        private int $challengeAt = 30,
        private int $denyAt = 70,
        private int $mismatchWeight = 60,
        private int $unfamiliarWeight = 25,
        private int $failureWeight = 25,
        private int $highFailureWeight = 50,
        private int $concentrationWeight = 25,
        private int $trustRelief = 50,
        private int $failureAt = 3,
        private int $highFailureAt = 10,
        private bool $shadow = false,
    ) {
        if ($challengeAt < 1 || $denyAt <= $challengeAt || $denyAt > 500
            || $failureAt < 1 || $highFailureAt <= $failureAt
            || $highFailureWeight < $failureWeight
            || count($agentMarkers) > 1024
        ) {
            throw new \InvalidArgumentException('Invalid risk policy thresholds.');
        }
        foreach ([$mismatchWeight, $unfamiliarWeight, $failureWeight, $highFailureWeight, $concentrationWeight, $trustRelief] as $weight) {
            if ($weight < 0 || $weight > 100) {
                throw new \InvalidArgumentException('Invalid risk policy weight.');
            }
        }
        $this->agentMarkers = self::normalizeMarkers($agentMarkers);
    }

    /**
     * Familiarity is null when telemetry is missing or unavailable.
     * Device trust and hard denial must come from independent verified policy.
     *
     * @throws \InvalidArgumentException
     */
    public function evaluate(
        RiskContext $context,
        ?bool $familiar = null,
        int $failedSignatureAttempts = 0,
        bool $concentrated = false,
        bool $verifiedTrust = false,
        bool $hardDeny = false,
    ): RiskAssessment {
        if ($failedSignatureAttempts < 0) {
            throw new \InvalidArgumentException('Invalid risk failure count.');
        }

        $score = 0;
        $reasons = [];
        if ($this->mismatchedAgent($context)) {
            $score += $this->mismatchWeight;
            $reasons[] = 'agent_fingerprint_mismatch';
        }
        if ($context->ja4 !== null && $familiar === false) {
            $score += $this->unfamiliarWeight;
            $reasons[] = 'unfamiliar_fingerprint';
        }

        if ($failedSignatureAttempts >= $this->highFailureAt) {
            $score += $this->highFailureWeight;
            $reasons[] = 'high_failure_velocity';
        } elseif ($failedSignatureAttempts >= $this->failureAt) {
            $score += $this->failureWeight;
            $reasons[] = 'failure_velocity';
        }
        if ($concentrated) {
            $score += $this->concentrationWeight;
            $reasons[] = 'network_concentration';
        }
        if ($verifiedTrust) {
            $score -= $this->trustRelief;
            $reasons[] = 'verified_device';
        }
        $score = max(0, $score);
        if ($hardDeny) {
            $reasons[] = 'hard_deny';

            return new RiskAssessment('deny', $score, $reasons, $this->shadow);
        }

        $action = match (true) {
            $score >= $this->denyAt => 'deny',
            $score >= $this->challengeAt => 'challenge',
            default => 'allow',
        };

        return new RiskAssessment($action, $score, $reasons, $this->shadow);
    }

    /** @throws \InvalidArgumentException */
    private static function normalizeMarker(mixed $marker): string
    {
        if (!is_string($marker) || $marker === '' || strlen($marker) > 64 || preg_match('/[\x00-\x1f\x7f]/', $marker) !== 0) {
            throw new \InvalidArgumentException('Invalid curated agent marker.');
        }

        return strtolower($marker);
    }

    /**
     * @param array<array-key,mixed> $agentMarkers
     * @return array<string,list<string>>
     * @throws \InvalidArgumentException
     */
    private static function normalizeMarkers(array $agentMarkers): array
    {
        $normalized = [];
        foreach ($agentMarkers as $fingerprint => $markers) {
            if (!is_string($fingerprint) || preg_match('/\A[tqd][a-z0-9]{2}[di][0-9]{4}[a-z0-9]{2}_[a-f0-9]{12}_[a-f0-9]{12}\z/D', $fingerprint) !== 1
                || !is_array($markers) || $markers === [] || !array_is_list($markers) || count($markers) > 8
            ) {
                throw new \InvalidArgumentException('Invalid curated fingerprint mapping.');
            }
            $normalized[$fingerprint] = array_map(self::normalizeMarker(...), $markers);
        }

        return $normalized;
    }

    private function mismatchedAgent(RiskContext $context): bool
    {
        if ($context->ja4 === null || $context->userAgent === null) {
            return false;
        }
        $markers = $this->agentMarkers[$context->ja4] ?? null;
        if ($markers === null) {
            return false;
        }
        $agent = strtolower($context->userAgent);

        return !array_any($markers, static fn(string $marker): bool => str_contains($agent, $marker));
    }
}
