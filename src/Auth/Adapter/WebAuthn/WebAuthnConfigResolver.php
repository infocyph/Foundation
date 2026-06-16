<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class WebAuthnConfigResolver
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function resolve(): WebAuthnConfig
    {
        $configured = $this->config->get('auth.webauthn', []);
        $resolved = WebAuthnConfig::fromArray(is_array($configured) ? $configured : []);

        if ($resolved->rpId === null || $resolved->origin === null) {
            throw new ConfigurationException(
                'auth.webauthn.rp_id and auth.webauthn.origin are required when auth.drivers.passkey=webauthn.',
            );
        }

        return $resolved;
    }
}
