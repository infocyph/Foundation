# Database migrations and seeding

Foundation composes DBLayer 4.1 for application database configuration,
migrations, schema inspection, and operational commands. DBLayer remains the
owner of connections, queries, transactions, schema grammar, migration
execution, replicas, pooling, query caching, and telemetry.

Install the canonical `database` module and publish `config/database.php`:

```bash
php infbyte module:install database
```

`db` and `dblayer` remain accepted module aliases, but public documentation uses
`database` as the canonical capability name.

Application services may type-hint `Infocyph\DBLayer\Connection\Connection` to
receive the configured default connection. Generic database work should use
DBLayer directly rather than a Foundation database facade.

## Foundation-owned schemas

The `database` module owns database infrastructure; it does not own an
application schema. Capability schemas are installed through their purpose
modules.

Foundation authentication schema:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
```

Database-backed browser sessions:

```bash
php infbyte module:schema:status session
php infbyte module:schema:install session
```

Database-backed CacheLayer stores/invalidation transports are similarly managed
through the `cache` module when applicable:

```bash
php infbyte module:schema:status cache
php infbyte module:schema:install cache
```

To provision every schema required by the current application configuration:

```bash
php infbyte module:schema:sync
```

All schema commands accept `--connection=<name>` where the provisioner supports
a DBLayer connection override. `module:remove` never drops schemas or
application data.

Foundation auth MFA factors include a scalar `revision` column used for
portable compare-and-swap state transitions. JSON metadata remains payload and
is never the synchronization token.

## Explicit application migration manifest

Application migrations are declared under `database.migrations`; Foundation
performs no directory scanning:

```php
return [
    'default' => 'sqlite',

    'migrations' => [
        'classes' => [
            App\Database\Migration\CreateAccountsMigration::class,
            App\Database\Migration\CreateInvoicesMigration::class,
        ],
        'table' => 'migrations',
        'lock_store' => null,
        'lock_wait_seconds' => 10.0,
        'lock_lease_seconds' => 300.0,
    ],

    'seeders' => [
        App\Database\Seeder\ProductionSeeder::class,
    ],
];
```

Migration classes implement `Infocyph\DBLayer\Migration\Migration` and use
DBLayer `SchemaManager`/`Blueprint`.

Generate starting points without modifying configuration automatically:

```bash
php infbyte create:migration CreateAccounts
php infbyte create:seeder Production
```

Then add the generated classes to `database.migrations.classes` or
`database.seeders` explicitly.

## Migration commands

```bash
php infbyte migrate
php infbyte migrate --step
php infbyte migrate --pretend
php infbyte migrate:status
php infbyte migrate:rollback --batches=1
php infbyte migrate:rollback --batch=3
php infbyte migrate:refresh --force
php infbyte migrate:reset --force
php infbyte migrate:fresh --force
```

Every migration command accepts `--connection=<name>`.

`migrate --pretend` delegates directly to DBLayer 4.1
`MigrationRunner::pretend()`. DBLayer returns pending migration IDs mapped to an
ordered list of `sql` + `bindings` records. Foundation only renders that native
preview; it does not implement a second SQL compiler or migration engine.

Destructive migration operations require `--force` in non-interactive mode.
When `database.migrations.lock_store` is configured, Foundation obtains the
selected CacheLayer coordination lock and DBLayer refreshes ownership through
its migration checkpoints.

## Seeding

Registered seeders run in configured order:

```bash
php infbyte db:seed
php infbyte db:seed --no-transaction
php infbyte db:seed --connection=reporting
```

`transaction` is a negatable option: seeders are transactional by default, and
`--no-transaction` disables that behavior. There is no runtime seeder scan.

## Inspection and operations

Inspect the selected connection:

```bash
php infbyte db:show
php infbyte db:show --connection=reporting
php infbyte db:table users
php infbyte db:table events --connection=reporting
```

Drop all user tables only with explicit destructive authorization:

```bash
php infbyte db:wipe --force
php infbyte db:wipe --connection=testing --force
```

DBLayer operational monitoring is available through:

```bash
php infbyte db:monitor
php infbyte db:monitor --section=status
php infbyte db:monitor --section=sessions
php infbyte db:monitor --section=queries --seconds=10
php infbyte db:monitor --section=locks
php infbyte db:monitor --section=tables
php infbyte db:monitor --section=indexes
php infbyte db:monitor --section=replication
php infbyte db:monitor --section=maintenance
```

Full snapshots may opt into more expensive maintenance data with
`--maintenance`. Foundation only selects the connection/options and renders
DBLayer `DatabaseMonitor` output.

## Persistent runtime cleanup

Every DBLayer connection resolved through Foundation participates in the
execution-boundary cleanup managed by `RuntimeContextTracker`. Shared
connections are sanitized for reuse; execution-local state must not leak into
the next Web request, CLI execution, worker message, or scheduled unit.
