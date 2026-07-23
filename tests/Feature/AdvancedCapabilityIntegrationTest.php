<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\WebAuthn\NoneWebAuthnAttestationPolicy;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnAttestationPolicyInterface;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfig;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnRuntime;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

final class FoundationDirectAttestationPolicy implements WebAuthnAttestationPolicyInterface
{
    public bool $configured = false;

    public function configure(WebAuthnConfig $config, CeremonyStepManagerFactory $factory): void
    {
        $this->configured = $config->attestation === 'direct' && $factory instanceof CeremonyStepManagerFactory;
    }

    public function supportManager(WebAuthnConfig $config): AttestationStatementSupportManager
    {
        if ($config->attestation !== 'direct') {
            throw new \LogicException('The test policy only supports direct attestation.');
        }

        return AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
        ]);
    }
}

final class FoundationPipelineTransport implements TransportInterface
{
    public function send(CommunicationRequest $request): CommunicationResult
    {
        return CommunicationResult::success(200, $request->transport);
    }
}

it('supports secure HOTP and OCRA MFA workflows', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'mfa' => 'otp',
                'storage' => 'memory',
            ],
        ],
    ])->boot();

    $mfa = $app->auth()->mfa;
    $hotp = new HOTP('JBSWY3DPEHPK3PXP');
    $hotpEnrollment = $mfa->enrollFactor(
        'account-hotp',
        MfaFactorType::HOTP,
        'Hardware token',
        ['otp' => ['algorithm' => 'sha1', 'counter' => 0, 'digits' => 6, 'secret' => 'JBSWY3DPEHPK3PXP']],
        enabled: true,
    );

    $hotpFactorId = $hotpEnrollment->factor?->id;
    expect($hotpFactorId)->toBeString()->not->toBeEmpty();

    $firstChallenge = $mfa->issueChallenge('account-hotp', factorId: $hotpFactorId);
    $firstVerification = $mfa->verifyChallenge((string) $firstChallenge->challenge?->id, $hotp->getOTP(0));
    $replayedChallenge = $mfa->issueChallenge('account-hotp', factorId: $hotpFactorId);
    $replayedVerification = $mfa->verifyChallenge((string) $replayedChallenge->challenge?->id, $hotp->getOTP(0));
    $secondVerification = $mfa->verifyChallenge((string) $replayedChallenge->challenge?->id, $hotp->getOTP(1));

    expect($firstVerification->successful())->toBeTrue()
        ->and($replayedVerification->successful())->toBeFalse()
        ->and($replayedVerification->code)->toBe('mfa_code_invalid')
        ->and($secondVerification->successful())->toBeTrue();

    $ocra = new OCRA('OCRA-1:HOTP-SHA1-6:QN08', '12345678901234567890');
    $ocraEnrollment = $mfa->enrollFactor(
        'account-ocra',
        MfaFactorType::OCRA,
        'Challenge token',
        ['otp' => ['shared_key' => '12345678901234567890', 'suite' => 'OCRA-1:HOTP-SHA1-6:QN08']],
        enabled: true,
    );
    $ocraFactorId = $ocraEnrollment->factor?->id;
    $challengeValue = '12345678';
    $ocraChallenge = $mfa->issueChallenge(
        'account-ocra',
        factorId: $ocraFactorId,
        context: ['ocra_challenge' => $challengeValue],
    );
    $ocraVerification = $mfa->verifyChallenge((string) $ocraChallenge->challenge?->id, $ocra->generate($challengeValue));
    $replayedOcraChallenge = $mfa->issueChallenge(
        'account-ocra',
        factorId: $ocraFactorId,
        context: ['ocra_challenge' => $challengeValue],
    );
    $replayedOcraVerification = $mfa->verifyChallenge((string) $replayedOcraChallenge->challenge?->id, $ocra->generate($challengeValue));

    expect($ocraVerification->successful())->toBeTrue()
        ->and($replayedOcraVerification->successful())->toBeFalse()
        ->and($replayedOcraVerification->code)->toBe('mfa_code_replayed');
});

it('keeps direct WebAuthn attestation fail-closed until a policy is registered', function (): void {
    $config = WebAuthnConfig::fromArray(['attestation' => 'direct']);

    expect(fn() => new NoneWebAuthnAttestationPolicy()->supportManager($config))
        ->toThrow(ConfigurationException::class);

    $policy = new FoundationDirectAttestationPolicy();
    $runtime = new WebAuthnRuntime($config, $policy);
    $runtime->attestationValidator();

    expect($policy->configured)->toBeTrue();
});

it('exposes lean TalkingBytes composition helpers', function (): void {
    $app = Foundation::web()->boot();
    $comms = $app->communication();
    $signer = $comms->hmacSigner('secret');
    $pipeline = $comms->pipeline(new FoundationPipelineTransport());

    expect($comms->signatureVerifier($signer)->verify('payload', $signer->sign('payload')))->toBeTrue()
        ->and($pipeline->send(new CommunicationRequest('GET', '/health'))->successful)->toBeTrue()
        ->and($app->notifications()->dkimVerifier())->toBeObject();
});
