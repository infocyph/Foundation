<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\RuntimeMode;

final class CommandCatalog
{
    /** @return array<string, CommandDefinition> */
    public function all(): array
    {
        $definitions = [
            new CommandDefinition('about', 'Show application and runtime information.', 'Application'),
            new CommandDefinition('app:install', 'Install the application runtime structure.', 'Application'),
            new CommandDefinition('app:ready', 'Run deployment readiness diagnostics.', 'Application'),
            new CommandDefinition('env:show', 'Show the active application environment.', 'Application'),
            (new CommandDefinition('serve', 'Run the local development HTTP server.', 'Application', capabilities: ['web']))
                ->option('host', 'Bind host.', acceptsValue: true)
                ->option('port', 'Bind port.', acceptsValue: true)
                ->option('dry-run', 'Validate the server configuration without starting it.'),

            new CommandDefinition('auth:schema:status', 'Show authentication schema status.', 'Authentication & Security', capabilities: ['db']),
            new CommandDefinition('auth:schema:install', 'Install authentication schema.', 'Authentication & Security', capabilities: ['db']),
            (new CommandDefinition('secret:generate', 'Generate secure application secret material.', 'Authentication & Security', capabilities: ['crypto']))
                ->option('force', 'Replace existing secret material.'),

            (new CommandDefinition('cache:clear', 'Clear a configured cache store.', 'Cache', capabilities: ['cache']))
                ->option('store', 'Configured cache store name.', acceptsValue: true),

            new CommandDefinition('config:cache', 'Compile application configuration.', 'Configuration'),
            new CommandDefinition('config:clear', 'Clear compiled configuration.', 'Configuration'),
            (new CommandDefinition('config:show', 'Show resolved configuration.', 'Configuration'))
                ->argument('key', 'Dot-notation configuration key.', required: true),

            new CommandDefinition('command:cache', 'Compile command metadata.', 'Commands'),
            new CommandDefinition('command:clear', 'Clear compiled command metadata.', 'Commands'),

            new CommandDefinition('db:seed', 'Run database seeders.', 'Database', capabilities: ['db']),
            new CommandDefinition('db:show', 'Show database information.', 'Database', capabilities: ['db']),
            (new CommandDefinition('db:table', 'Show database table information.', 'Database', capabilities: ['db']))
                ->argument('table', 'Database table name.', required: true),
            new CommandDefinition('migrate', 'Run pending migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:fresh', 'Drop schema and rerun migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:refresh', 'Rollback and rerun migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:reset', 'Rollback all migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:rollback', 'Rollback the latest migration batch.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:status', 'Show migration status.', 'Database', capabilities: ['db']),

            $this->generator('class', 'Create a class.'),
            $this->generator('command', 'Create a command.'),
            $this->generator('controller', 'Create a controller.'),
            $this->generator('enum', 'Create an enum.'),
            $this->generator('event', 'Create an event.'),
            $this->generator('exception', 'Create an exception.'),
            $this->generator('interface', 'Create an interface.'),
            $this->generator('job', 'Create a job.'),
            $this->generator('listener', 'Create a listener.'),
            $this->generator('migration', 'Create a migration.', ['db']),
            $this->generator('middleware', 'Create middleware.'),
            $this->generator('policy', 'Create an authorization policy.'),
            $this->generator('provider', 'Create a service provider.'),
            $this->generator('repository', 'Create a repository.'),
            $this->generator('seeder', 'Create a database seeder.'),
            $this->generator('service', 'Create an application service.'),
            $this->generator('test', 'Create a test.'),
            $this->generator('trait', 'Create a trait.'),
            $this->generator('worker', 'Create a worker.'),

            (new CommandDefinition(
                'queue:consume',
                'Consume queued messages.',
                'Messaging',
                RuntimeMode::Worker,
                ['messaging'],
            ))
                ->option('transport', 'Receiver transport name.', acceptsValue: true)
                ->option('queue', 'Queue name.', acceptsValue: true)
                ->option('limit', 'Maximum messages for this receive.', acceptsValue: true),
            (new CommandDefinition(
                'schedule:dispatch-message',
                'Dispatch due scheduled messages.',
                'Messaging',
                RuntimeMode::Scheduler,
                ['messaging'],
            ))->argument('name', 'Scheduled message name.', required: true),

            (new CommandDefinition('module:install', 'Install an optional Foundation module.', 'Modules'))
                ->argument('module', 'Module name.', required: true),
            new CommandDefinition('module:list', 'List Foundation modules.', 'Modules'),
            (new CommandDefinition('module:remove', 'Remove an optional Foundation module.', 'Modules'))
                ->argument('module', 'Module name.', required: true),

            new CommandDefinition('optimize', 'Compile supported runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:clear', 'Clear compiled runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:report', 'Report optimization artifact state.', 'Optimization'),

            new CommandDefinition('route:cache', 'Compile application routes.', 'Routing', capabilities: ['web']),
            new CommandDefinition('route:clear', 'Clear compiled routes.', 'Routing'),
            (new CommandDefinition('route:list', 'List application routes.', 'Routing', capabilities: ['web']))
                ->option('json', 'Render machine-readable JSON output.'),

            new CommandDefinition('schedule:cache', 'Compile scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:clear', 'Clear compiled scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:list', 'List scheduled work.', 'Scheduling'),
            new CommandDefinition('schedule:run', 'Run due scheduled work once.', 'Scheduling', RuntimeMode::Scheduler),
            new CommandDefinition('schedule:work', 'Run the persistent scheduler loop.', 'Scheduling', RuntimeMode::Scheduler),

            (new CommandDefinition('session:prune', 'Prune expired sessions.', 'Sessions'))
                ->option('limit', 'Maximum sessions to prune.', acceptsValue: true),
            new CommandDefinition('session:schema:install', 'Install session persistence schema.', 'Sessions', capabilities: ['db']),
            new CommandDefinition('session:schema:status', 'Show session schema status.', 'Sessions', capabilities: ['db']),

            new CommandDefinition('storage:link', 'Create configured public storage links.', 'Storage', capabilities: ['filesystem']),

            new CommandDefinition('worker:list', 'List configured workers.', 'Workers'),
            (new CommandDefinition('worker:run', 'Run a persistent worker.', 'Workers', RuntimeMode::Worker))
                ->argument('name', 'Configured worker name.', required: true),
        ];

        $catalog = [];
        foreach ($definitions as $definition) {
            $definition->assertComplete();
            $catalog[$definition->commandName()] = $definition;
        }

        return $catalog;
    }

    /** @return array<string, CommandDescriptor> */
    public function descriptors(): array
    {
        $descriptors = [];
        foreach ($this->all() as $name => $definition) {
            $descriptors[$name] = new CommandDescriptor($definition, system: true);
        }

        return $descriptors;
    }

    public function find(string $name): ?CommandDefinition
    {
        return $this->all()[$name] ?? null;
    }

    public function runtimeFor(string $name): RuntimeMode
    {
        return $this->find($name)?->commandRuntime() ?? RuntimeMode::Cli;
    }

    /** @param list<string> $capabilities */
    private function generator(string $artifact, string $description, array $capabilities = []): CommandDefinition
    {
        $definition = (new CommandDefinition('create:' . $artifact, $description, 'Generators', capabilities: $capabilities))
            ->argument('name', 'Application-relative class name.', required: true)
            ->option('force', 'Replace an existing artifact.');

        if (in_array($artifact, ['repository', 'migration'], true)) {
            $definition->option('table', 'Explicit table or schema-qualified table name.', acceptsValue: true);
        }

        return $definition;
    }
}
