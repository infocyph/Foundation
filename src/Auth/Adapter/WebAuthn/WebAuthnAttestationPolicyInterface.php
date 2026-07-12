<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

interface WebAuthnAttestationPolicyInterface
{
    public function configure(WebAuthnConfig $config, CeremonyStepManagerFactory $factory): void;

    public function supportManager(WebAuthnConfig $config): AttestationStatementSupportManager;
}
