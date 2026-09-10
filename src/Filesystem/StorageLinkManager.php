<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\FileManager\SafeSymlinkManager;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class StorageLinkManager
{
    private SafeSymlinkManager $links;

    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
    ) {
        $this->links = new SafeSymlinkManager(
            $this->paths->public(),
            $this->paths->storage(),
        );
    }

    /** @return list<array{link:string,target:string,created:bool}> */
    public function create(): array
    {
        $created = [];
        foreach ($this->configured() as $mapping) {
            $created[] = [
                'link' => $mapping['link'],
                'target' => $mapping['target'],
                'created' => $this->links->create(
                    $mapping['link'],
                    $mapping['target'],
                    createTargetDirectory: true,
                ),
            ];
        }

        return $created;
    }

    /** @return list<array{link:string,target:string,removed:bool}> */
    public function remove(): array
    {
        $removed = [];
        foreach ($this->configured() as $mapping) {
            try {
                $didRemove = $this->links->remove($mapping['link'], $mapping['target']);
            } catch (PolicyViolationException $exception) {
                if (str_contains($exception->getMessage(), 'current target does not match')) {
                    throw new PolicyViolationException(
                        'Refusing to remove symbolic link because its current target does not match configuration.',
                        0,
                        $exception,
                    );
                }

                throw $exception;
            }

            $removed[] = [
                'link' => $mapping['link'],
                'target' => $mapping['target'],
                'removed' => $didRemove,
            ];
        }

        return $removed;
    }

    /** @return list<array{link:string,target:string,exists:bool,linked:bool,matches:bool,broken:bool}> */
    public function status(): array
    {
        $statuses = [];
        foreach ($this->configured() as $mapping) {
            $status = $this->links->status($mapping['link'], $mapping['target']);
            $statuses[] = [
                'link' => $status->link,
                'target' => $status->target,
                'exists' => $status->exists,
                'linked' => $status->linked,
                'matches' => $status->matches,
                'broken' => $status->broken,
            ];
        }

        return $statuses;
    }

    private function absolute(string $path): string
    {
        $path = trim($path);

        return PathHelper::isAbsolute($path)
            ? PathHelper::normalize($path)
            : $this->paths->base(trim($path, '/\\'));
    }

    /** @return list<array{link:string,target:string}> */
    private function configured(): array
    {
        $configured = $this->config->get('filesystem.links', []);
        if (!is_array($configured) || $configured === []) {
            throw new \RuntimeException('No filesystem.links are configured.');
        }

        $links = [];
        foreach ($configured as $link => $target) {
            if (!is_string($link) || trim($link) === '' || !is_string($target) || trim($target) === '') {
                throw new \InvalidArgumentException(
                    'filesystem.links must map link paths to target paths.',
                );
            }

            $links[] = [
                'link' => $this->absolute($link),
                'target' => $this->absolute($target),
            ];
        }

        return $links;
    }
}
