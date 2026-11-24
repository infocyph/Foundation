<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\Webrick\Request\Support\IpCidr;

/**
 * Execution-local risk facts. Network attestation is not user authentication.
 * The host supplies the actual transport peer, precompiled trusted-edge networks
 * and single-valued, edge-overwritten claims; never use client-supplied trust flags.
 *
 * @api
 */
final readonly class RiskContext
{
    private function __construct(
        public string $tenant,
        public string $purpose,
        public string $ip,
        public string $prefix,
        public bool $attested,
        public ?string $ja4,
        public ?string $asn,
        public ?string $userAgent,
    ) {}

    /**
     * Untrusted peers cannot contribute JA4, ASN or forwarded client identity.
     * An authorized edge must overwrite these fields and isolate origin access.
     *
     * @param list<IpCidr> $trustedEdges
     * @param list<string> $clientIps
     * @param list<string> $fingerprints
     * @param list<string> $autonomousSystems
     * @param list<string> $userAgents
     * @throws \InvalidArgumentException
     */
    public static function fromTransport(
        string $tenant,
        string $purpose,
        string $peer,
        array $trustedEdges = [],
        array $clientIps = [],
        array $fingerprints = [],
        array $autonomousSystems = [],
        array $userAgents = [],
    ): self {
        if (preg_match('/\A[A-Za-z0-9_.:\/-]{1,128}\z/D', $tenant) !== 1
            || preg_match('/\A[A-Za-z0-9_.:\/-]{1,128}\z/D', $purpose) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid risk scope.');
        }

        $peer = self::address($peer);
        $attested = array_any($trustedEdges, static fn(IpCidr $network): bool => $network->matches($peer));
        [$ip, $ja4, $asn] = $attested
            ? self::attestedSignals($clientIps, $fingerprints, $autonomousSystems)
            : [$peer, null, null];

        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException('Invalid client address.');
        }
        $width = strlen($packed) === 4 ? 3 : 8;
        $network = inet_ntop(str_pad(substr($packed, 0, $width), strlen($packed), "\0"));
        if ($network === false) {
            throw new \InvalidArgumentException('Invalid client network.');
        }

        return new self(
            $tenant,
            $purpose,
            $ip,
            $network . ($width === 3 ? '/24' : '/64'),
            $attested,
            $ja4,
            $asn,
            self::single($userAgents, 512, true),
        );
    }

    /** @throws \InvalidArgumentException */
    private static function address(string $value): string
    {
        if (strlen($value) > 45 || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Invalid transport address.');
        }
        $packed = inet_pton($value);
        if ($packed === false) {
            throw new \InvalidArgumentException('Invalid transport address.');
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $address = inet_ntop($packed);
        if ($address === false) {
            throw new \InvalidArgumentException('Invalid transport address.');
        }

        return $address;
    }

    /**
     * @param list<string> $clientIps
     * @param list<string> $fingerprints
     * @param list<string> $autonomousSystems
     * @return array{string,?string,?string}
     * @throws \InvalidArgumentException
     */
    private static function attestedSignals(array $clientIps, array $fingerprints, array $autonomousSystems): array
    {
        $client = self::single($clientIps, 45);
        if ($client === null) {
            throw new \InvalidArgumentException('Trusted edge must supply one client address.');
        }
        $ip = self::address($client);
        $ja4 = self::single($fingerprints, 36);
        if ($ja4 !== null && preg_match('/\A[tqd][a-z0-9]{2}[di][0-9]{4}[a-z0-9]{2}_[a-f0-9]{12}_[a-f0-9]{12}\z/D', $ja4) !== 1) {
            throw new \InvalidArgumentException('Invalid attested fingerprint.');
        }
        $asn = self::single($autonomousSystems, 10);
        if ($asn !== null && (preg_match('/\A[1-9][0-9]{0,9}\z/D', $asn) !== 1
            || (strlen($asn) === 10 && strcmp($asn, '4294967295') > 0))
        ) {
            throw new \InvalidArgumentException('Invalid attested ASN.');
        }

        return [$ip, $ja4, $asn];
    }

    /**
     * @param list<string> $values
     * @throws \InvalidArgumentException
     */
    private static function single(array $values, int $maximum, bool $allowComma = false): ?string
    {
        if ($values === []) {
            return null;
        }
        if (count($values) !== 1) {
            throw new \InvalidArgumentException('Ambiguous risk signal.');
        }
        $value = $values[0];
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
            || (!$allowComma && str_contains($value, ','))
        ) {
            throw new \InvalidArgumentException('Invalid risk signal.');
        }

        return $value;
    }
}
