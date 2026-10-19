<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class WebAuthnConfigValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @param list<ConfigIssue> $issues */
    public function validate(array &$issues, bool $assumeProduction): void
    {
        $rpId = $this->config->get('auth.webauthn.rp_id');
        $challengeTtl = $this->config->get('auth.webauthn.challenge_ttl', 300);
        $allowSubdomains = $this->config->get('auth.webauthn.allow_subdomains', false);

        if (!is_string($rpId) || $rpId === '') {
            $issues[] = new ConfigIssue(
                'auth.webauthn.rp_id must be configured when auth.drivers.passkey uses webauthn.',
                'auth.webauthn.rp_id',
            );
        }

        $origin = $this->webAuthnOrigin($issues, $this->config->get('auth.webauthn.origin'));
        if ($origin === null) {
            return;
        }

        if ($assumeProduction && $origin['scheme'] !== 'https' && !$this->isLocalWebAuthnHost($origin['host'])) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must use https outside localhost/local development.',
                'auth.webauthn.origin',
            );
        }

        $ttl = $this->positiveIntegerValue($challengeTtl);
        if ($ttl === null || $ttl > 600) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.challenge_ttl must be between 1 and 600 seconds.',
                'auth.webauthn.challenge_ttl',
            );
        }

        if (!is_bool($allowSubdomains)) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.allow_subdomains must be a boolean.',
                'auth.webauthn.allow_subdomains',
            );
        }
    }

    /** @param array<string, mixed> $parts */
    private function isExactWebAuthnOrigin(array $parts): bool
    {
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';

        return is_string($scheme)
            && is_string($host)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && $path === '';
    }

    private function isLocalWebAuthnHost(mixed $host): bool
    {
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function positiveIntegerValue(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($validated) ? $validated : null;
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return array{scheme:string,host:string}|null
     */
    private function webAuthnOrigin(array &$issues, mixed $origin): ?array
    {
        if (!is_string($origin) || $origin === '') {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must be configured when auth.drivers.passkey uses webauthn.',
                'auth.webauthn.origin',
            );

            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !$this->isExactWebAuthnOrigin($parts)) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must be an exact HTTP(S) origin without path, credentials, query, or fragment.',
                'auth.webauthn.origin',
            );

            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        return [
            'scheme' => strtolower($scheme),
            'host' => $host,
        ];
    }
}
