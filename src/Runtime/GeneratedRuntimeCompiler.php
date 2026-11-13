<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\InterMix\Exceptions\ContainerException;

/** Compiles one verified CLI, worker, or scheduler InterMix artifact. */
final class GeneratedRuntimeCompiler
{
    /**
     * @param array<string,mixed> $config
     * @param array<int|string,mixed> $capabilities Explicit optional capability topology.
     * @return array{
     *   runtime:string,
     *   path:string,
     *   digest:string,
     *   compiled:list<string>,
     *   skipped:array<string,string>,
     *   metadata_path:string,
     *   metadata_sha256:string,
     *   capabilities:array<string,bool>
     * }
     * @throws \RuntimeException
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function compile(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        array $capabilities = [],
    ): array {
        $graph = new NonWebGraphFactory()->compose($config, $runtime, $capabilities);
        $builder = FoundationGraph::compose($graph->context);
        $graph->bootstrapper->contribute($builder, $graph->context, $graph->providers);
        new NonWebProductionGraph()->prepare($builder);

        $artifactPath = GeneratedRuntimeMetadata::resolvePath($graph->application, $artifactPath);
        $directory = dirname($artifactPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create generated runtime directory "%s".', $directory));
        }

        $builder->validate(strict: true);

        // Native InterMix strict compilation rejects skipped definitions before
        // publishing an artifact. Avoid separately planning the graph twice.
        try {
            $report = $builder->compile($artifactPath, strict: true);
        } catch (ContainerException $exception) {
            if (!str_starts_with($exception->getMessage(), 'Strict static compilation rejected unsupported definitions:')) {
                throw $exception;
            }

            throw new \RuntimeException(
                'Foundation generated runtime contains definitions that were not statically compiled: '
                . $exception->getMessage(),
                previous: $exception,
            );
        }
        $this->assertNoSkippedDefinitions($report['skipped']);
        GeneratedRuntimeMetadata::write(
            $artifactPath,
            GeneratedRuntimeMetadata::create($graph, [
                'compiled' => $report['compiled'],
                'skipped' => $report['skipped'],
                'digest' => $report['digest'],
            ]),
        );
        $metadataSha256 = hash_file('sha256', GeneratedRuntimeMetadata::path($artifactPath));
        if (!is_string($metadataSha256)) {
            throw new \RuntimeException('Unable to hash generated runtime metadata.');
        }

        return [
            'runtime' => $runtime->value,
            'path' => $artifactPath,
            'digest' => $report['digest'],
            'compiled' => $report['compiled'],
            'skipped' => $report['skipped'],
            'metadata_path' => GeneratedRuntimeMetadata::path($artifactPath),
            'metadata_sha256' => $metadataSha256,
            'capabilities' => $graph->context->capabilities,
        ];
    }

    /**
     * @param array<string,string> $skipped
     * @throws \RuntimeException
     */
    private function assertNoSkippedDefinitions(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $details = [];
        foreach ($skipped as $id => $reason) {
            $details[] = sprintf('%s: %s', $id, $reason);
        }

        throw new \RuntimeException(
            'Foundation generated runtime contains definitions that were not statically compiled: '
            . implode('; ', $details),
        );
    }
}
