<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Generator\ArtifactGenerator;

final class ArtifactSystemCommand extends SystemCommand
{
    public function __construct(private readonly ArtifactGenerator $generator) {}

    protected function handle(): int
    {
        $artifact = substr($this->canonicalName(), strlen('create:'));
        $name = $this->argument(0);
        if ($artifact === '' || $name === null) {
            throw new \LogicException('Validated artifact command metadata is unavailable.');
        }

        $result = $this->generator->create(
            $artifact,
            $name,
            $this->flag('force'),
            $this->option('table'),
        );

        if ($this->io()->machineReadable()) {
            $this->io()->json($result);
        } else {
            $this->io()->success(sprintf('Created %s at %s.', $result['class'], $result['path']));
        }

        return ExitCode::SUCCESS;
    }
}
