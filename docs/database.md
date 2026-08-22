# Database migrations and seeding

Install DBLayer and publish `config/database.php`:

```bash
php infbyte module:install db
```

The template publishes first-class MySQL, MariaDB, PostgreSQL, Microsoft SQL
Server, and SQLite connection examples using only settings supported by each
DBLayer 4.1 driver. Foundation selects application connection names and resolves
application-relative SQLite paths; DBLayer owns connection validation, SQL,
replicas, pooling, transactions, query caching, telemetry, and schema behavior.

Application services may type-hint `Infocyph\DBLayer\Connection\Connection` to
receive Foundation's configured default DBLayer connection directly. Use
`Infocyph\DBLayer\DB` or the connection's native query/repository APIs rather
than a second Foundation database facade.

## Explicit migration manifest

Register ordered migration classes:

```php
'migrations' => [
    'classes' => [
        App\Database\Migration\CreateAccounts::class,
        App\Database\Migration\CreateInvoices::class,
    ],
    'table' => 'migrations',
    'lock_store' => 'redis',
    'lock_wait_seconds' => 10.0,
    'lock_lease_seconds' => 300.0,
],
```

Classes implement `Infocyph\DBLayer\Migration\Migration` and use DBLayer
`SchemaManager`/`Blueprint`. Foundation performs no filesystem discovery.

Generate a registered-class starting point without scanning the project:

```bash
php infbyte create:migration CreateAccounts
php infbyte create:seeder Production
```

The commands create `App\Database\Migration\CreateAccountsMigration` and
`App\Database\Seeder\ProductionSeeder`. Add those classes to
`database.migrations.classes` and `database.seeders`; generation never edits
application configuration implicitly. Migration identifiers include a UTC
timestamp and descriptive suffix, and existing files are preserved unless
`--force` is supplied.

Available commands:

```bash
php infbyte migrate
php infbyte migrate --step=true
php infbyte migrate:status
php infbyte migrate:rollback --batches=1
php infbyte migrate:refresh --force=true
php infbyte migrate:reset --force=true
php infbyte migrate:fresh --force=true
```

Destructive operations require explicit `--force=true`. If `lock_store` is
null, no distributed lock is used. Otherwise Foundation requests the named
CacheLayer lock provider; the backend may be file, Redis, Valkey, Memcached, or
PDO according to CacheLayer configuration.

## Seeders

Register classes under `database.seeders`. They implement DBLayer `Seeder` or
are invokable services:

```bash
php infbyte db:seed
php infbyte db:seed --transactional=false
```

Seed order is the configured order. There is no runtime scanning.

## Database inspection

Inspect the selected connection and its user tables:

```bash
php infbyte db:show
php infbyte db:show --connection=reporting --json=true
php infbyte db:table users
php infbyte db:table reporting.events --connection=reporting
```

Inspection commands are Foundation application tooling; actual connection and
schema operations are delegated to DBLayer. These commands connect only when
selected and do not participate in web bootstrap.

## Testing

```php
use Infocyph\DBLayer\Connection\Connection;

$db = $app->testing()->database();
$connection = $app->make(Connection::class);

$result = $db->transaction(function () use ($connection) {
    return $connection->table('accounts')->insert([...]);
});

$db->refresh();
```

`transaction()` always rolls back in `finally`. `refresh()` explicitly
authorizes DBLayer's destructive refresh and reruns the registered migrations.

In persistent web, worker, or scheduler runtimes, every DBLayer connection
resolved by Foundation participates in the execution boundary cleanup. Shared
connections are sanitized for reuse, while `fresh=true` connections are tracked
and reset independently.
