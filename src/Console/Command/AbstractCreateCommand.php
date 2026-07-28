<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\ArtifactGenerator;

abstract class AbstractCreateCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ArtifactGenerator $generator) {}

    abstract protected function artifact(): string;

    abstract protected function commandName(): string;

    final protected static function configure(
        CommandDefinition $command,
        string $name,
        string $description,
        string $example,
    ): void {
        $command
            ->name($name)
            ->description($description)
            ->argument(Argument::required('name')->description(
                'Application-relative class name, for example: ' . $example . '.',
            ))
            ->option(Option::flag('force')->description(
                'Atomically replace an existing artifact. Allowed values: present|absent.',
            ));
    }

    final protected function handle(): int
    {
        try {
            $result = $this->generator->create(
                $this->artifact(),
                $this->arguments()->string('name'),
                $this->options()->bool('force'),
                $this->table(),
            );
        } catch (\Throwable $exception) {
            $this->io()->error($this->commandName() . ' failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Created ' . $this->artifact() . ' at: ' . $result['path']);
        $hint = $this->registrationHint($result['class']);
        if ($hint !== null) {
            $this->io()->info($hint);
        }

        return ExitCode::SUCCESS;
    }

    protected function registrationHint(string $class): ?string
    {
        return match ($this->artifact()) {
            'command' => sprintf('Register %s in routes/console.php.', $class),
            'provider' => sprintf(
                'Assign %s to common, web, or console in bootstrap/providers.php.',
                $class,
            ),
            'worker' => sprintf('Map a worker name to %s in routes/workers.php.', $class),
            default => null,
        };
    }

    protected function table(): ?string
    {
        return null;
    }
}
