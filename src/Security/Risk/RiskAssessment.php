<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

/**
 * Domain verdict; shadow is observational and must not be used to spend proof,
 * update telemetry or grant a hash lease. HTTP admission belongs to Webrick.
 *
 * @api
 */
final readonly class RiskAssessment
{
    /**
     * @param list<string> $reasons
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $action,
        public int $score,
        public array $reasons,
        public bool $shadow,
    ) {
        if (!in_array($action, ['allow', 'challenge', 'deny'], true)) {
            throw new \InvalidArgumentException('Invalid risk assessment action.');
        }
    }
}
