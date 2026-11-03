<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use InvalidArgumentException;

/**
 * Verify exact Composer lock identities and a reviewed upgrade delta.
 * Host measurements are validated separately by Foundation4HostComparison.
 */
final class Foundation4HostUpgradeEvidence
{
    /**
     * @return array{sha256:string,packages:array<string,array{version:string,source_ref:?string,dist_ref:?string}>}
     */
    public static function fromLock(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Required Composer lock evidence is not readable.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('Unable to read Composer lock evidence.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Composer lock evidence must contain an object.');
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $group) {
            $entries = $decoded[$group] ?? [];
            if (!is_array($entries)) {
                throw new InvalidArgumentException('Composer lock package listing is malformed.');
            }

            foreach ($entries as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null)) {
                    throw new InvalidArgumentException('Composer lock package name is invalid.');
                }
                $identity = self::identity($package);
                $name = $package['name'];
                if (isset($packages[$name])) {
                    throw new InvalidArgumentException('Duplicate Composer package in lock evidence: ' . $name);
                }
                $packages[$name] = $identity;
            }
        }

        if ($packages === []) {
            throw new InvalidArgumentException('Composer lock evidence has no resolved packages.');
        }

        ksort($packages);

        return ['sha256' => hash('sha256', $contents), 'packages' => $packages];
    }

    /**
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $evidence
     * @return list<string>
     */
    public static function verify(array $baseline, array $candidate, array $evidence): array
    {
        if (($evidence['format'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported dependency upgrade evidence format.');
        }

        $before = self::manifest($evidence['baseline'] ?? null);
        $after = self::manifest($evidence['candidate'] ?? null);
        if ($before['sha256'] === $after['sha256']) {
            throw new InvalidArgumentException('Dependency upgrade comparison requires distinct Composer locks.');
        }

        foreach (['baseline' => $baseline, 'candidate' => $candidate] as $side => $report) {
            $manifest = $side === 'baseline' ? $before : $after;
            if (($report['composer_lock_sha256'] ?? null) !== $manifest['sha256']) {
                throw new InvalidArgumentException('Host report Composer lock does not match ' . $side . ' evidence.');
            }
        }

        foreach (['tool_sha', 'fixture_id'] as $field) {
            if (!is_string($baseline[$field] ?? null) || $baseline[$field] === ''
                || ($baseline[$field] !== ($candidate[$field] ?? null))
            ) {
                throw new InvalidArgumentException('Incomparable upgrade host evidence: ' . $field . ' differs.');
            }
        }

        $allowed = $evidence['allowed_changes'] ?? null;
        if (!is_array($allowed)) {
            throw new InvalidArgumentException('Reviewed dependency changes are required.');
        }

        return self::review($before, $after, $allowed);
    }

    /**
     * Check an exact package/ref delta before spending time on host measurements.
     *
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $allowed
     * @return list<string>
     */
    public static function review(array $baseline, array $candidate, array $allowed): array
    {
        $before = self::manifest($baseline);
        $after = self::manifest($candidate);
        if ($before['sha256'] === $after['sha256']) {
            throw new InvalidArgumentException('Dependency upgrade review requires distinct Composer locks.');
        }

        $changes = self::changes($before['packages'], $after['packages']);
        if ($changes === []) {
            throw new InvalidArgumentException('Reviewed, nonempty dependency changes are required.');
        }

        ksort($allowed);
        if ($changes !== $allowed) {
            throw new InvalidArgumentException('Unreviewed or mismatched dependency upgrade changes.');
        }

        return array_keys($changes);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{before:mixed,after:mixed}>
     */
    private static function changes(array $before, array $after): array
    {
        $result = [];
        $names = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $old = $before[$name] ?? null;
            $new = $after[$name] ?? null;
            if ($old !== $new) {
                $result[$name] = ['before' => $old, 'after' => $new];
            }
        }

        return $result;
    }

    /**
     * @return array{version:string,source_ref:?string,dist_ref:?string}
     */
    private static function identity(mixed $package): array
    {
        if (!is_array($package)
            || !is_string($package['name'] ?? null)
            || !is_string($package['version'] ?? null)
            || $package['name'] === ''
            || $package['version'] === ''
        ) {
            throw new InvalidArgumentException('Composer lock package identity is invalid.');
        }

        $source = $package['source'] ?? null;
        $dist = $package['dist'] ?? null;

        return [
            'version' => $package['version'],
            'source_ref' => is_array($source) && is_string($source['reference'] ?? null) ? $source['reference'] : null,
            'dist_ref' => is_array($dist) && is_string($dist['reference'] ?? null) ? $dist['reference'] : null,
        ];
    }

    /**
     * @return array{sha256:string,packages:array<string,mixed>}
     */
    private static function manifest(mixed $manifest): array
    {
        if (!is_array($manifest)
            || !is_string($manifest['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256']) !== 1
            || !is_array($manifest['packages'] ?? null)
        ) {
            throw new InvalidArgumentException('Dependency upgrade lock manifest is invalid.');
        }

        /** @var array<string,mixed> $packages */
        $packages = $manifest['packages'];

        return ['sha256' => $manifest['sha256'], 'packages' => $packages];
    }
}
