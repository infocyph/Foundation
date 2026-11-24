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
     * @param 'allow'|'challenge'|'deny' $action
     * @param list<string> $reasons
     */
    public function __construct(
        public string $action,
        public int $score,
        public array $reasons,
        public bool $shadow,
    ) {}
}
