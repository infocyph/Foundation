<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Explicit protected login route action. The application supplies trusted
 * transport facts and a normalized login identifier; ordinary routes do
 * not instantiate this service. Never put clearance or proof in audit context.
 *
 * @api
 */
final readonly class RiskLogin
{
    public function __construct(
        private Authenticator $authenticator,
        private RiskPolicy $policy,
        private RiskTelemetry $telemetry,
        private ?RiskProofStore $proofs = null,
        private ?RiskDeviceTrust $devices = null,
        private ?RiskHashBulkhead $bulkhead = null,
    ) {}

    /**
     * A canonical account ID, if supplied, must be resolved by the host for
     * this identifier, not accepted from the client. The renderer owns the
     * application's normal authentication response.
     *
     * @param callable(LoginResult):Response $render
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \Random\RandomException
     */
    public function handle(
        Request $request,
        LoginRequest $login,
        RiskContext $context,
        int $now,
        callable $render,
        #[\SensitiveParameter]
        ?string $clearance = null,
        #[\SensitiveParameter]
        ?string $deviceCookie = null,
        ?string $canonicalAccount = null,
        bool $hardDeny = false,
    ): Response {
        $account = $login->identifier;
        $observed = $this->telemetry->observe($context, $now, $account);
        $trusted = $canonicalAccount !== null && $deviceCookie !== null
            && $this->devices !== null
            && $this->devices->verify($context, $canonicalAccount, $deviceCookie, $now);
        $assessment = $this->policy->evaluate(
            $context,
            $observed['familiar'],
            $observed['failedSignatureAttempts'],
            $observed['concentrated'],
            $trusted,
            $hardDeny,
        );

        $decision = $this->decision($assessment, $context, $account, $now, $clearance);
        $middleware = new RiskAdmissionMiddleware(
            static fn(): RiskDecision => $decision,
            $assessment->shadow ? RiskAdmissionMiddleware::SHADOW : RiskAdmissionMiddleware::ENFORCE,
        );

        return $middleware(
            $request,
            fn(): Response => $this->authenticate($login, $context, $now, $render, $assessment->shadow),
        );
    }

    /**
     * @param callable(LoginResult):Response $render
     * @throws \Random\RandomException
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    private function authenticate(LoginRequest $login, RiskContext $context, int $now, callable $render, bool $shadow): Response
    {
        $feedback = $shadow ? null : function (bool $success) use ($context, $now, $login): void {
            if ($success) {
                $this->telemetry->recordSuccess($context);
            } else {
                $this->telemetry->recordFailure($context, $now, $login->identifier);
            }
        };
        if ($shadow || $this->bulkhead === null) {
            return $render($this->authenticator->login($login, $feedback))->withHeader('Cache-Control', 'no-store');
        }

        $lease = $this->bulkhead->acquire();
        if ($lease === null) {
            return Response::json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        try {
            $result = $this->authenticator->login($login, $feedback);
        } finally {
            $this->bulkhead->release($lease);
        }

        return $render($result)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private function decision(
        RiskAssessment $assessment,
        RiskContext $context,
        string $account,
        int $now,
        #[\SensitiveParameter]
        ?string $clearance,
    ): RiskDecision {
        $decision = match ($assessment->action) {
            'allow' => RiskDecision::allow($assessment->score, $assessment->reasons),
            'deny' => RiskDecision::deny($assessment->score, $assessment->reasons),
            'challenge' => RiskDecision::challenge($assessment->score, $assessment->reasons),
            default => throw new \UnexpectedValueException('Invalid risk action.'),
        };
        if ($assessment->shadow || $decision->action !== RiskDecision::CHALLENGE || $clearance === null) {
            return $decision;
        }
        $redeemed = $this->proofs?->redeemClearance($context, $account, $clearance, $now);

        return match ($redeemed) {
            true => RiskDecision::allow($assessment->score, ['challenge_cleared']),
            false => $decision,
            null => RiskDecision::unavailable($assessment->score, ['clearance_unavailable']),
        };
    }
}
