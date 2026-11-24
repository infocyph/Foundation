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
        #[\SensitiveParameter] ?string $clearance = null,
        #[\SensitiveParameter] ?string $deviceCookie = null,
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

        $decision = match ($assessment->action) {
            'allow' => RiskDecision::allow($assessment->score, $assessment->reasons),
            'deny' => RiskDecision::deny($assessment->score, $assessment->reasons),
            'challenge' => RiskDecision::challenge($assessment->score, $assessment->reasons),
        };
        if (!$assessment->shadow && $decision->action === RiskDecision::CHALLENGE && $clearance !== null) {
            $redeemed = $this->proofs?->redeemClearance($context, $account, $clearance, $now);
            $decision = match ($redeemed) {
                true => RiskDecision::allow($assessment->score, ['challenge_cleared']),
                false => $decision,
                null => RiskDecision::unavailable($assessment->score, ['clearance_unavailable']),
            };
        }

        $middleware = new RiskAdmissionMiddleware(
            static fn(Request $_request): RiskDecision => $decision,
            $assessment->shadow ? RiskAdmissionMiddleware::SHADOW : RiskAdmissionMiddleware::ENFORCE,
        );

        return $middleware($request, function (Request $_request) use ($login, $context, $now, $account, $render, $assessment): Response {
            $feedback = $assessment->shadow ? null : function (bool $success) use ($context, $now, $account): void {
                if ($success) {
                    $this->telemetry->recordSuccess($context);
                } else {
                    $this->telemetry->recordFailure($context, $now, $account);
                }
            };

            $lease = null;
            if (!$assessment->shadow && $this->bulkhead !== null) {
                $lease = $this->bulkhead->acquire();
                if ($lease === null) {
                    return Response::json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
                }
            }

            try {
                $result = $this->authenticator->login($login, $feedback);
            } finally {
                if ($lease !== null) {
                    $this->bulkhead?->release($lease);
                }
            }

            return $render($result)->withHeader('Cache-Control', 'no-store');
        });
    }
}
