<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

use Composer\InstalledVersions;

/**
 * @phpstan-import-type PackageResolution from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-import-type PackageState from \Infocyph\Foundation\Module\ModuleStateResolver
 */
final class ModulePackageStateResolver
{
    /**
     * @param array<string,string> $requirements
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @phpstan-return PackageResolution
     */
    public function resolve(array $requirements, array $ownership): array
    {
        /** @var array<string,PackageState> $packages */
        $packages = [];
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<string> $warnings */
        $warnings = [];

        foreach ($requirements as $package => $constraint) {
            $state = $this->state($package, $constraint, $ownership);
            $packages[$package] = $state;
            array_push($blockers, ...$this->blockers($package, $state));
            array_push($warnings, ...$this->warnings($package, $state));
        }

        if ($packages !== [] && !$ownership['known']) {
            $blockers[] = $ownership['error'] ?? 'Application Composer ownership is unknown.';
        }

        return [
            'packages' => $packages,
            'all_available' => !array_any($packages, static fn(array $state): bool => !$state['available']),
            'all_direct' => $packages === []
                || ($ownership['known'] && !array_any($packages, static fn(array $state): bool => !$state['direct'])),
            'any_transitive' => array_any($packages, static fn(array $state): bool => $state['transitive']),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @phpstan-param PackageState $state
     * @return list<string>
     */
    private function blockers(string $package, array $state): array
    {
        if (!$state['available']) {
            return [sprintf('Required package %s %s is not available.', $package, $state['constraint'])];
        }
        if ($state['catalog_compatible'] === false) {
            return [sprintf(
                'Installed package %s %s does not satisfy %s.',
                $package,
                $state['version'] ?? 'unknown',
                $state['constraint'],
            )];
        }
        if ($state['direct_constraint_compatible'] === false) {
            return [sprintf(
                'Direct Composer constraint %s for %s is outside the supported module range %s.',
                $state['direct_constraint'],
                $package,
                $state['constraint'],
            )];
        }
        if ($state['compatible'] === false) {
            return [sprintf(
                'Installed package %s %s does not satisfy the application constraint %s.',
                $package,
                $state['version'] ?? 'unknown',
                $state['direct_constraint'] ?? 'unknown',
            )];
        }

        return [];
    }

    private function combinedCompatibility(
        ?bool $catalog,
        ?bool $directConstraint,
        ?bool $directVersion,
        bool $direct,
    ): ?bool {
        if (in_array(false, [$catalog, $directConstraint, $directVersion], true)) {
            return false;
        }
        if ($catalog !== true) {
            return null;
        }
        if (!$direct) {
            return true;
        }

        return $directConstraint === true && $directVersion === true ? true : null;
    }

    /** @return array{lower:string,upper:string}|null */
    private function constraintBounds(string $constraint): ?array
    {
        if (preg_match('/^\\^(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?$/D', trim($constraint), $match) !== 1) {
            return null;
        }

        $major = (int) $match[1];
        $minor = isset($match[2]) ? (int) $match[2] : 0;
        $patch = isset($match[3]) ? (int) $match[3] : 0;
        $lower = sprintf('%d.%d.%d', $major, $minor, $patch);
        $upper = match (true) {
            $major > 0 => sprintf('%d.0.0', $major + 1),
            $minor > 0 => sprintf('0.%d.0', $minor + 1),
            default => sprintf('0.0.%d', $patch + 1),
        };

        return ['lower' => $lower, 'upper' => $upper];
    }

    private function constraintWithin(string $candidate, string $required): ?bool
    {
        $candidateBounds = $this->constraintBounds($candidate);
        $requiredBounds = $this->constraintBounds($required);
        if ($candidateBounds === null || $requiredBounds === null) {
            return null;
        }

        return version_compare($candidateBounds['lower'], $requiredBounds['lower'], '>=')
            && version_compare($candidateBounds['upper'], $requiredBounds['upper'], '<=');
    }

    private function satisfiesConstraint(?string $version, string $constraint): ?bool
    {
        if ($version === null) {
            return null;
        }

        $bounds = $this->constraintBounds($constraint);
        if ($bounds === null) {
            return null;
        }

        return version_compare($version, $bounds['lower'], '>=')
            && version_compare($version, $bounds['upper'], '<');
    }

    /**
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @phpstan-return PackageState
     */
    private function state(string $package, string $constraint, array $ownership): array
    {
        $available = InstalledVersions::isInstalled($package);
        $direct = $ownership['known'] && isset($ownership['requirements'][$package]);
        $directConstraint = $direct ? $ownership['requirements'][$package] : null;
        $version = $available ? InstalledVersions::getVersion($package) : null;
        $catalogCompatible = $available ? $this->satisfiesConstraint($version, $constraint) : null;
        $directConstraintCompatible = $directConstraint === null
            ? null
            : $this->constraintWithin($directConstraint, $constraint);
        $directVersionCompatible = !$available || $directConstraint === null
            ? null
            : $this->satisfiesConstraint($version, $directConstraint);

        return [
            'constraint' => $constraint,
            'installed' => $available,
            'available' => $available,
            'direct' => $direct,
            'transitive' => $ownership['known'] && $available && !$direct,
            'ownership_unknown' => !$ownership['known'],
            'direct_constraint' => $directConstraint,
            'catalog_compatible' => $catalogCompatible,
            'direct_constraint_compatible' => $directConstraintCompatible,
            'compatible' => $this->combinedCompatibility(
                $catalogCompatible,
                $directConstraintCompatible,
                $directVersionCompatible,
                $direct,
            ),
            'version' => $available ? InstalledVersions::getPrettyVersion($package) : null,
        ];
    }

    /**
     * @phpstan-param PackageState $state
     * @return list<string>
     */
    private function warnings(string $package, array $state): array
    {
        if ($state['direct'] && $state['compatible'] === null) {
            return [sprintf(
                'Unable to fully evaluate direct Composer constraint %s for %s against %s.',
                $state['direct_constraint'] ?? 'unknown',
                $package,
                $state['constraint'],
            )];
        }
        if ($state['transitive']) {
            return [sprintf(
                'Package %s is available only transitively; require it directly to own this module.',
                $package,
            )];
        }

        return [];
    }
}
