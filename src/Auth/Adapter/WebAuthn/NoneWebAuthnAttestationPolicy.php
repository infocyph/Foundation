<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Exception\ConfigurationException;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

final class NoneWebAuthnAttestationPolicy implements WebAuthnAttestationPolicyInterface
{
    public function configure(WebAuthnConfig $config, CeremonyStepManagerFactory $factory): void
    {
        $factory->setAttestationStatementSupportManager($this->supportManager($config));
    }

    public function supportManager(WebAuthnConfig $config): AttestationStatementSupportManager
    {
        $this->assertNone($config);

        return AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
        ]);
    }

    private function assertNone(WebAuthnConfig $config): void
    {
        if ($config->attestation !== 'none') {
            throw new ConfigurationException(
                'WebAuthn attestation modes other than "none" require a registered WebAuthnAttestationPolicyInterface.',
            );
        }
    }
}
