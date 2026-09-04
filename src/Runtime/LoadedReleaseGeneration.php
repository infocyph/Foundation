<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

/** Immutable deployment identity attached only after an active release is loaded. */
final readonly class LoadedReleaseGeneration
{
    public string $releaseRoot;

    public function __construct(
        string $releaseRoot,
        public string $generation,
        public ?string $trustedFoundationManifestSha256 = null,
    ) {
        $releaseRoot = rtrim($releaseRoot, DIRECTORY_SEPARATOR);
        if ($releaseRoot === '') {
            throw new \InvalidArgumentException('Loaded Foundation release root must not be empty.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1) {
            throw new \InvalidArgumentException('Loaded Foundation release generation identifier is invalid.');
        }
        if ($trustedFoundationManifestSha256 !== null
            && preg_match('/^[a-f0-9]{64}$/D', $trustedFoundationManifestSha256) !== 1
        ) {
            throw new \InvalidArgumentException('Loaded Foundation release trust identity is invalid.');
        }

        $this->releaseRoot = $releaseRoot;
    }
}
