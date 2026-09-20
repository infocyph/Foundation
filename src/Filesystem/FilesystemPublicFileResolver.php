<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Pathwise\Results\PublicFileResolution;
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
use Infocyph\Pathwise\StreamHandler\PublicFileSymlinkPolicy;
use Infocyph\Pathwise\Utils\PathHelper;

/** Applies Foundation public-root policy to Pathwise's canonical local resolver. */
final readonly class FilesystemPublicFileResolver
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
    ) {}

    public function resolve(string $relativePath): PublicFileResolution
    {
        return new PublicFileResolver()->resolve(
            $this->root(),
            $relativePath,
            $this->symlinkPolicy(),
        );
    }

    public function root(): string
    {
        $configured = $this->config->get('filesystem.public_files.root', 'public');
        $root = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'public';

        if (PathHelper::isAbsolute($root)) {
            return PathHelper::normalize($root);
        }
        if (PathHelper::hasScheme($root)) {
            throw new \InvalidArgumentException(
                'filesystem.public_files.root must be a local filesystem directory.',
            );
        }

        return $this->paths->base($root);
    }

    private function symlinkPolicy(): PublicFileSymlinkPolicy
    {
        $configured = $this->config->get(
            'filesystem.public_files.symlink_policy',
            PublicFileSymlinkPolicy::REJECT->value,
        );
        $value = is_string($configured)
            ? strtolower(trim($configured))
            : PublicFileSymlinkPolicy::REJECT->value;

        return PublicFileSymlinkPolicy::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf(
                'Unsupported filesystem.public_files.symlink_policy "%s".',
                $value,
            ));
    }
}
