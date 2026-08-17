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
            new CommandDefinition('serve', 'Run the local development HTTP server.', 'Application', capabilities: ['web']),

            new CommandDefinition('auth:schema:status', 'Show authentication schema status.', 'Authentication & Security', capabilities: ['db']),
            new CommandDefinition('auth:schema:install', 'Install authentication schema.', 'Authentication & Security', capabilities: ['db']),
            new CommandDefinition('secret:generate', 'Generate secure application secret material.', 'Authentication & Security', capabilities: ['crypto']),

            new CommandDefinition('cache:clear', 'Clear a configured cache store.', 'Cache', capabilities: ['cache']),

            new CommandDefinition('config:cache', 'Compile application configuration.', 'Configuration'),
            new CommandDefinition('config:clear', 'Clear compiled configuration.', 'Configuration'),
            new CommandDefinition('config:show', 'Show resolved configuration.', 'Configuration'),

            new CommandDefinition('command:cache', 'Compile command metadata.', 'Commands'),
            new CommandDefinition('command:clear', 'Clear compiled command metadata.', 'Commands'),

            new CommandDefinition('db:seed', 'Run database seeders.', 'Database', capabilities: ['db']),
            new CommandDefinition('db:show', 'Show database information.', 'Database', capabilities: ['db']),
            new CommandDefinition('db:table', 'Show database table information.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate', 'Run pending migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:fresh', 'Drop schema and rerun migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:refresh', 'Rollback and rerun migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:reset', 'Rollback all migrations.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:rollback', 'Rollback the latest migration batch.', 'Database', capabilities: ['db']),
            new CommandDefinition('migrate:status', 'Show migration status.', 'Database', capabilities: ['db']),

            new CommandDefinition('create:class', 'Create a class.', 'Generators'),
            new CommandDefinition('create:command', 'Create a command.', 'Generators'),
            new CommandDefinition('create:controller', 'Create a controller.', 'Generators'),
            new CommandDefinition('create:enum', 'Create an enum.', 'Generators'),
            new CommandDefinition('create:event', 'Create an event.', 'Generators'),
            new CommandDefinition('create:exception', 'Create an exception.', 'Generators'),
            new CommandDefinition('create:interface', 'Create an interface.', 'Generators'),
            new CommandDefinition('create:job', 'Create a job.', 'Generators'),
            new CommandDefinition('create:listener', 'Create a listener.', 'Generators'),
            new CommandDefinition('create:migration', 'Create a migration.', 'Generators', capabilities: ['db']),
            new CommandDefinition('create:middleware', 'Create middleware.', 'Generators'),
            new CommandDefinition('create:policy', 'Create an authorization policy.', 'Generators'),
            new CommandDefinition('create:provider', 'Create a service provider.', 'Generators'),
            new CommandDefinition('create:repository', 'Create a repository.', 'Generators'),
            new CommandDefinition('create:seeder', 'Create a database seeder.', 'Generators'),
            new CommandDefinition('create:service', 'Create an application service.', 'Generators'),
            new CommandDefinition('create:test', 'Create a test.', 'Generators'),
            new CommandDefinition('create:trait', 'Create a trait.', 'Generators'),
            new CommandDefinition('create:worker', 'Create a worker.', 'Generators'),

            new CommandDefinition('queue:consume', 'Consume queued messages.', 'Messaging', RuntimeMode::Worker, ['messaging']),
            new CommandDefinition('schedule:dispatch-message', 'Dispatch due scheduled messages.', 'Messaging', RuntimeMode::Scheduler, ['messaging']),

            new CommandDefinition('module:install', 'Install an optional Foundation module.', 'Modules'),
            new CommandDefinition('module:list', 'List Foundation modules.', 'Modules'),
            new CommandDefinition('module:remove', 'Remove an optional Foundation module.', 'Modules'),

            new CommandDefinition('optimize', 'Compile supported runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:clear', 'Clear compiled runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:report', 'Report optimization artifact state.', 'Optimization'),

            new CommandDefinition('route:cache', 'Compile application routes.', 'Routing', capabilities: ['web']),
            new CommandDefinition('route:clear', 'Clear compiled routes.', 'Routing'),
            new CommandDefinition('route:list', 'List application routes.', 'Routing', capabilities: ['web']),

            new CommandDefinition('schedule:cache', 'Compile scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:clear', 'Clear compiled scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:list', 'List scheduled work.', 'Scheduling'),
            new CommandDefinition('schedule:run', 'Run due scheduled work once.', 'Scheduling', RuntimeMode::Scheduler),
            new CommandDefinition('schedule:work', 'Run the persistent scheduler loop.', 'Scheduling', RuntimeMode::Scheduler),

            new CommandDefinition('session:prune', 'Prune expired sessions.', 'Sessions'),
            new CommandDefinition('session:schema:install', 'Install session persistence schema.', 'Sessions', capabilities: ['db']),
            new CommandDefinition('session:schema:status', 'Show session schema status.', 'Sessions', capabilities: ['db']),

            new CommandDefinition('storage:link', 'Create configured public storage links.', 'Storage', capabilities: ['filesystem']),

            new CommandDefinition('worker:list', 'List configured workers.', 'Workers'),
            new CommandDefinition('worker:run', 'Run a persistent worker.', 'Workers', RuntimeMode::Worker),
        ];

        $catalog = [];
        foreach ($definitions as $definition) {
            $catalog[$definition->name] = $definition;
        }

        return $catalog;
    }

    public function find(string $name): ?CommandDefinition
    {
        return $this->all()[$name] ?? null;
    }

    public function runtimeFor(string $name): RuntimeMode
    {
        return $this->find($name)?->runtime ?? RuntimeMode::Cli;
    }
}
