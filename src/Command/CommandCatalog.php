<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Command\System\ApplicationSystemCommand;
use Infocyph\Foundation\Command\System\ArtifactSystemCommand;
use Infocyph\Foundation\Command\System\CacheSystemCommand;
use Infocyph\Foundation\Command\System\DatabaseSystemCommand;
use Infocyph\Foundation\Command\System\MessagingSystemCommand;
use Infocyph\Foundation\Command\System\ModuleSystemCommand;
use Infocyph\Foundation\Command\System\OAuthSystemCommand;
use Infocyph\Foundation\Command\System\OperationsSystemCommand;
use Infocyph\Foundation\Command\System\RuntimeSystemCommand;

final class CommandCatalog
{
    /** @return array<string, CommandDefinition> */
    public function all(): array
    {
        $connection = static fn(CommandDefinition $command): CommandDefinition => $command
            ->option('connection', 'Configured database connection name.', acceptsValue: true);
        $destructive = static fn(CommandDefinition $command): CommandDefinition => $connection($command)
            ->option('force', 'Authorize the destructive operation without prompting.');
        $transport = static fn(CommandDefinition $command): CommandDefinition => $command
            ->option('transport', 'Configured messaging transport name.', acceptsValue: true);

        $definitions = [
            new CommandDefinition('about', 'Show application and runtime information.', 'Application'),
            new CommandDefinition('app:install', 'Install the application runtime structure.', 'Application'),
            new CommandDefinition('app:ready', 'Run deployment readiness diagnostics.', 'Application'),
            new CommandDefinition('env:show', 'Show the active application environment.', 'Application'),
            new CommandDefinition('serve', 'Run the local development HTTP server.', 'Application')
                ->option('host', 'Bind host.', acceptsValue: true)
                ->option('port', 'Bind port.', acceptsValue: true)
                ->option('dry-run', 'Validate the server configuration without starting it.'),

            new CommandDefinition('auth:prune', 'Prune expired and retained-revoked authentication records.', 'Authentication & Security', capabilities: ['db'])
                ->option('connection', 'Configured database connection name.', acceptsValue: true)
                ->option('retention-hours', 'Hours to retain revoked token/grant records.', acceptsValue: true),
            new CommandDefinition('auth:oauth:client:create', 'Create an OAuth client and display any generated secret once.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->option('type', 'Client type: public|confidential.', acceptsValue: true)
                ->option('grant', 'Allowed grant. Repeat for multiple grants.', acceptsValue: true, multiple: true)
                ->option('redirect-uri', 'Exact registered redirect URI. Repeat as needed.', acceptsValue: true, multiple: true)
                ->option('scope', 'Allowed OAuth scope. Repeat as needed.', acceptsValue: true, multiple: true)
                ->option('audience', 'Allowed resource audience. Repeat as needed.', acceptsValue: true, multiple: true)
                ->option('native-client', 'Allow production loopback HTTP redirects for a native public client.'),
            new CommandDefinition('auth:oauth:client:list', 'List OAuth clients using a bounded result set.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->option('limit', 'Maximum clients, from 1 to 500.', acceptsValue: true),
            new CommandDefinition('auth:oauth:client:show', 'Show one OAuth client without secret material.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->argument('client', 'OAuth client identifier.', required: true),
            new CommandDefinition('auth:oauth:client:rotate-secret', 'Rotate a confidential OAuth client secret and display it once.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->argument('client', 'OAuth client identifier.', required: true),
            new CommandDefinition('auth:oauth:client:enable', 'Enable an OAuth client.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->argument('client', 'OAuth client identifier.', required: true),
            new CommandDefinition('auth:oauth:client:disable', 'Disable an OAuth client.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->argument('client', 'OAuth client identifier.', required: true),
            new CommandDefinition('auth:oauth:authorization:list', 'List OAuth authorizations using a bounded result set.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->option('limit', 'Maximum authorizations, from 1 to 500.', acceptsValue: true)
                ->option('client', 'Filter by exact OAuth client identifier.', acceptsValue: true),
            new CommandDefinition('auth:oauth:authorization:revoke', 'Revoke one OAuth authorization.', 'Authentication & Security', capabilities: ['db', 'crypto'])
                ->argument('authorization', 'OAuth authorization identifier.', required: true)
                ->option('force', 'Authorize revocation without prompting.'),
            new CommandDefinition('auth:oauth:key:check', 'Validate OAuth signing-key readiness without exposing key material.', 'Authentication & Security', capabilities: ['db', 'crypto']),
            new CommandDefinition(
                'secret:generate',
                'Generate secure application secret material.',
                'Authentication & Security',
            )->option('force', 'Replace existing secret material.'),

            new CommandDefinition('cache:clear', 'Clear a configured cache store.', 'Cache', capabilities: ['cache'])
                ->option('store', 'Configured cache store name.', acceptsValue: true),
            new CommandDefinition('cache:forget', 'Forget one cache item.', 'Cache', capabilities: ['cache'])
                ->argument('key', 'Cache key.', required: true)
                ->option('store', 'Configured cache store name.', acceptsValue: true),

            new CommandDefinition('config:cache', 'Compile application configuration.', 'Configuration'),
            new CommandDefinition('config:clear', 'Clear compiled configuration.', 'Configuration'),
            new CommandDefinition('config:show', 'Show resolved configuration.', 'Configuration')
                ->argument('key', 'Dot-notation configuration key.', required: true),
            new CommandDefinition('config:validate', 'Validate application configuration.', 'Configuration')
                ->option('production', 'Validate using production requirements regardless of current environment.'),

            new CommandDefinition('command:cache', 'Compile command metadata.', 'Commands'),
            new CommandDefinition('command:clear', 'Clear compiled command metadata.', 'Commands'),

            $connection(
                new CommandDefinition('db:monitor', 'Inspect database operational health and metrics.', 'Database', capabilities: ['db'])
                    ->option('section', 'snapshot|status|sessions|queries|locks|tables|indexes|replication|maintenance.', acceptsValue: true)
                    ->option('seconds', 'Long-running query threshold in seconds.', acceptsValue: true)
                    ->option('maintenance', 'Include expensive maintenance information in full snapshots.'),
            ),
            $connection(
                new CommandDefinition('db:seed', 'Run database seeders.', 'Database', capabilities: ['db'])
                    ->option('transaction', 'Run seeders transactionally.', negatable: true),
            ),
            $connection(new CommandDefinition('db:show', 'Show database information.', 'Database', capabilities: ['db'])),
            $connection(
                new CommandDefinition('db:table', 'Show database table information.', 'Database', capabilities: ['db'])
                    ->argument('table', 'Database table name.', required: true),
            ),
            $destructive(new CommandDefinition('db:wipe', 'Drop all user tables.', 'Database', capabilities: ['db'])),
            $connection(
                new CommandDefinition('migrate', 'Run pending migrations.', 'Database', capabilities: ['db'])
                    ->option('step', 'Create a new migration batch for each migration.')
                    ->option('pretend', 'Compile and display pending SQL without executing it.'),
            ),
            $destructive(new CommandDefinition('migrate:fresh', 'Drop schema and rerun migrations.', 'Database', capabilities: ['db'])),
            $destructive(new CommandDefinition('migrate:refresh', 'Rollback and rerun migrations.', 'Database', capabilities: ['db'])),
            $destructive(new CommandDefinition('migrate:reset', 'Rollback all migrations.', 'Database', capabilities: ['db'])),
            $connection(
                new CommandDefinition('migrate:rollback', 'Rollback migration batches.', 'Database', capabilities: ['db'])
                    ->option('batches', 'Number of latest migration batches to roll back.', acceptsValue: true)
                    ->option('batch', 'Exact migration batch number to roll back.', acceptsValue: true),
            ),
            $connection(new CommandDefinition('migrate:status', 'Show migration status.', 'Database', capabilities: ['db'])),

            new CommandDefinition('env:encrypt', 'Encrypt an environment file using Epicrypt.', 'Environment', capabilities: ['crypto'])
                ->option('input', 'Source environment file.', acceptsValue: true)
                ->option('output', 'Encrypted destination file.', acceptsValue: true)
                ->option('key-file', 'File containing environment protection key material.', acceptsValue: true)
                ->option('key-env', 'Environment variable containing protection key material.', acceptsValue: true)
                ->option('force', 'Replace an existing destination file.'),
            new CommandDefinition('env:decrypt', 'Decrypt an Epicrypt-protected environment file.', 'Environment', capabilities: ['crypto'])
                ->option('input', 'Encrypted source file.', acceptsValue: true)
                ->option('output', 'Decrypted destination file.', acceptsValue: true)
                ->option('key-file', 'File containing environment protection key material.', acceptsValue: true)
                ->option('key-env', 'Environment variable containing protection key material.', acceptsValue: true)
                ->option('force', 'Replace an existing destination file.'),

            new CommandDefinition('execution:list', 'List Foundation execution history.', 'Operations')
                ->option('limit', 'Maximum history records.', acceptsValue: true)
                ->option('kind', 'Filter by execution kind.', acceptsValue: true)
                ->option('name', 'Filter by execution name.', acceptsValue: true),
            new CommandDefinition('execution:show', 'Show state transitions for one execution.', 'Operations')
                ->argument('id', 'Execution identifier.', required: true),
            new CommandDefinition('execution:clear', 'Clear Foundation execution history.', 'Operations')
                ->option('force', 'Clear without prompting.'),

            $this->generator('class', 'Create a class.'),
            $this->generator('command', 'Create a command.'),
            $this->generator('config', 'Create an application configuration file.'),
            $this->generator('controller', 'Create a controller.'),
            $this->generator('enum', 'Create an enum.'),
            $this->generator('event', 'Create an event.'),
            $this->generator('exception', 'Create an exception.'),
            $this->generator('handler', 'Create a messaging handler.', ['messaging']),
            $this->generator('interface', 'Create an interface.'),
            $this->generator('job', 'Create a job message.', ['messaging']),
            $this->generator('job-middleware', 'Create job middleware.', ['messaging']),
            $this->generator('listener', 'Create a listener.'),
            $this->generator('mail', 'Create an application mail message.', ['communication']),
            $this->generator('middleware', 'Create HTTP middleware.'),
            $this->generator('migration', 'Create a migration.', ['db']),
            $this->generator('notification', 'Create an application notification.', ['communication']),
            $this->generator('notification-channel', 'Create a notification channel.', ['communication']),
            $this->generator('policy', 'Create an authorization policy.'),
            $this->generator('provider', 'Create a service provider.'),
            $this->generator('repository', 'Create a repository.', ['db']),
            $this->generator('request', 'Create a validated HTTP request.', ['validation']),
            $this->generator('resource', 'Create a JSON resource.'),
            $this->generator('rule', 'Create a ReqShield validation rule.', ['validation']),
            $this->generator('seeder', 'Create a database seeder.', ['db']),
            $this->generator('service', 'Create an application service.'),
            $this->generator('test', 'Create a test.'),
            $this->generator('trait', 'Create a trait.'),
            $this->generator('worker', 'Create a worker.'),

            new CommandDefinition('log:tail', 'Tail the built-in structured file log.', 'Logging')
                ->option('lines', 'Initial number of lines.', acceptsValue: true)
                ->option('follow', 'Continue following appended log records.'),

            new CommandDefinition('maintenance:enable', 'Enable application maintenance mode.', 'Maintenance')
                ->option('retry', 'Retry-After value in seconds.', acceptsValue: true)
                ->option('message', 'Maintenance response message.', acceptsValue: true),
            new CommandDefinition('maintenance:disable', 'Disable application maintenance mode.', 'Maintenance'),
            new CommandDefinition('maintenance:status', 'Show application maintenance state.', 'Maintenance'),

            new CommandDefinition('messaging:list', 'Inspect configured messaging routes, handlers, middleware, listeners and workers.', 'Messaging', capabilities: ['messaging']),
            new CommandDefinition(
                'queue:consume',
                'Consume queued messages.',
                'Messaging',
                RuntimeMode::Worker,
                ['messaging'],
            )
                ->option('transport', 'Receiver transport name.', acceptsValue: true)
                ->option('queue', 'Queue name.', acceptsValue: true)
                ->option('limit', 'Maximum messages for this receive.', acceptsValue: true)
                ->option('visibility', 'Visibility timeout in seconds.', acceptsValue: true),
            new CommandDefinition('queue:failed', 'List failed messages.', 'Messaging', capabilities: ['messaging'])
                ->option('limit', 'Maximum failed messages.', acceptsValue: true),
            new CommandDefinition('queue:failed:show', 'Show one failed message.', 'Messaging', capabilities: ['messaging'])
                ->argument('id', 'Failed-message identifier.', required: true),
            new CommandDefinition('queue:forget', 'Forget one failed message.', 'Messaging', capabilities: ['messaging'])
                ->argument('id', 'Failed-message identifier.', required: true),
            new CommandDefinition('queue:flush', 'Flush all failed messages.', 'Messaging', capabilities: ['messaging'])
                ->option('force', 'Flush without prompting.'),
            $transport(
                new CommandDefinition('queue:monitor', 'Show the size of a receiving queue.', 'Messaging', capabilities: ['messaging'])
                    ->option('queue', 'Queue name.', acceptsValue: true),
            ),
            new CommandDefinition('queue:prune-failed', 'Prune old failed messages.', 'Messaging', capabilities: ['messaging'])
                ->option('hours', 'Prune failures older than this many hours.', acceptsValue: true),
            $transport(
                new CommandDefinition('queue:retry', 'Retry one failed message.', 'Messaging', capabilities: ['messaging'])
                    ->argument('id', 'Failed-message identifier.', required: true)
                    ->option('queue', 'Override retry queue.', acceptsValue: true),
            ),
            new CommandDefinition(
                'schedule:dispatch-message',
                'Dispatch a configured scheduled message.',
                'Messaging',
                RuntimeMode::Scheduler,
                ['messaging'],
            )->argument('name', 'Scheduled message name.', required: true),

            $connection(
                new CommandDefinition(
                    'module:install',
                    'Install a Foundation module, publish config, and provision applicable schemas.',
                    'Modules',
                )
                    ->argument('module', 'Module name.', required: true)
                    ->option('dry-run', 'Preview Composer changes without modifying the project.'),
            ),
            new CommandDefinition('module:list', 'List Foundation modules.', 'Modules'),
            new CommandDefinition('module:show', 'Show detailed module package/config/schema state.', 'Modules')
                ->argument('module', 'Module name.', required: true)
                ->option('connection', 'Database connection for schema inspection.', acceptsValue: true),
            new CommandDefinition('module:config:publish', 'Publish config owned by a Foundation module.', 'Modules')
                ->argument('module', 'Module name.', required: true)
                ->option('force', 'Replace existing module config.'),
            new CommandDefinition('module:remove', 'Remove an optional Foundation module.', 'Modules')
                ->argument('module', 'Module name.', required: true)
                ->option('dry-run', 'Preview Composer changes without modifying the project.'),
            $connection(
                new CommandDefinition('module:schema:install', 'Provision database schemas owned by a module.', 'Modules')
                    ->argument('module', 'Module name.', required: true),
            ),
            $connection(
                new CommandDefinition('module:schema:status', 'Show database schema readiness for a module.', 'Modules')
                    ->argument('module', 'Module name.', required: true),
            ),
            $connection(new CommandDefinition(
                'module:schema:sync',
                'Provision all module schemas required by current configuration.',
                'Modules',
            )),

            new CommandDefinition('optimize', 'Compile supported runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:clear', 'Clear compiled runtime artifacts.', 'Optimization'),
            new CommandDefinition('optimize:report', 'Report optimization artifact state.', 'Optimization'),

            new CommandDefinition('runtime:reload', 'Request graceful reload of persistent Foundation runtimes.', 'Operations'),

            new CommandDefinition('route:cache', 'Compile application routes.', 'Routing', capabilities: ['web'])
                ->option('matcher', 'Webrick matcher strategy.', acceptsValue: true)
                ->option('cache', 'Compiled route cache path.', acceptsValue: true)
                ->option('routes', 'Comma-separated route files.', acceptsValue: true),
            new CommandDefinition('route:clear', 'Clear compiled routes.', 'Routing'),
            new CommandDefinition('route:list', 'List application routes.', 'Routing', capabilities: ['web'])
                ->option('routes', 'Comma-separated route files.', acceptsValue: true),

            new CommandDefinition('schedule:cache', 'Compile scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:clear', 'Clear compiled scheduler metadata.', 'Scheduling'),
            new CommandDefinition('schedule:interrupt', 'Request graceful interruption of persistent scheduler loops.', 'Scheduling'),
            new CommandDefinition('schedule:list', 'List scheduled work.', 'Scheduling'),
            new CommandDefinition('schedule:run', 'Run due scheduled work once.', 'Scheduling', RuntimeMode::Scheduler),
            new CommandDefinition('schedule:test', 'Run one scheduled entry regardless of due state.', 'Scheduling', RuntimeMode::Scheduler)
                ->argument('name', 'Schedule key or unique command name.', required: true),
            new CommandDefinition('schedule:work', 'Run the persistent scheduler loop.', 'Scheduling', RuntimeMode::Scheduler)
                ->option('sleep', 'Seconds between scheduler iterations.', acceptsValue: true)
                ->option('max-iterations', 'Stop after the given number of scheduler iterations.', acceptsValue: true),

            new CommandDefinition('session:prune', 'Prune expired sessions.', 'Sessions')
                ->option('limit', 'Maximum sessions to prune.', acceptsValue: true),

            new CommandDefinition('storage:link', 'Create configured public storage links.', 'Storage', capabilities: ['filesystem']),
            new CommandDefinition('storage:status', 'Inspect configured public storage links.', 'Storage', capabilities: ['filesystem']),
            new CommandDefinition('storage:unlink', 'Remove configured public storage links safely.', 'Storage', capabilities: ['filesystem']),

            new CommandDefinition('worker:list', 'List configured workers.', 'Workers'),
            new CommandDefinition('worker:restart', 'Request graceful worker restart.', 'Workers')
                ->argument('name', 'Optional configured worker name.'),
            new CommandDefinition('worker:status', 'Show configured/running worker state.', 'Workers')
                ->argument('name', 'Optional configured worker name.'),
            new CommandDefinition('worker:run', 'Run a persistent worker.', 'Workers', RuntimeMode::Worker)
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
            $descriptors[$name] = new CommandDescriptor(
                $definition,
                $this->handler($name),
                system: true,
            );
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
        $definition = new CommandDefinition('create:' . $artifact, $description, 'Generators', capabilities: $capabilities)
            ->argument('name', 'Application-relative class name.', required: true)
            ->option('force', 'Replace an existing artifact.');

        if (in_array($artifact, ['repository', 'migration'], true)) {
            $definition->option('table', 'Explicit table or schema-qualified table name.', acceptsValue: true);
        }

        return $definition;
    }

    /** @return class-string<CommandHandlerInterface> */
    private function handler(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'create:') => ArtifactSystemCommand::class,
            str_starts_with($name, 'module:') => ModuleSystemCommand::class,
            str_starts_with($name, 'auth:oauth:') => OAuthSystemCommand::class,
            $name === 'cache:forget' => CacheSystemCommand::class,
            str_starts_with($name, 'db:'),
            str_starts_with($name, 'migrate') => DatabaseSystemCommand::class,
            $name === 'messaging:list',
            str_starts_with($name, 'queue:failed'),
            in_array($name, ['queue:flush', 'queue:forget', 'queue:monitor', 'queue:prune-failed', 'queue:retry'], true)
                => MessagingSystemCommand::class,
            str_starts_with($name, 'execution:'),
            str_starts_with($name, 'maintenance:'),
            in_array($name, [
                'auth:prune',
                'config:validate',
                'env:decrypt',
                'env:encrypt',
                'log:tail',
                'runtime:reload',
                'schedule:interrupt',
                'worker:restart',
                'worker:status',
            ], true) => OperationsSystemCommand::class,
            str_starts_with($name, 'route:'),
            str_starts_with($name, 'schedule:'),
            str_starts_with($name, 'session:'),
            str_starts_with($name, 'worker:'),
            $name === 'queue:consume',
            str_starts_with($name, 'storage:') => RuntimeSystemCommand::class,
            default => ApplicationSystemCommand::class,
        };
    }
}
