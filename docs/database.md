# Database migrations and seeding

Install DBLayer and publish `config/database.php`:

```bash
php infbyte module:install db
```

The template contains separate MySQL/MariaDB, PostgreSQL, and SQLite examples
using only keys supported by that driver. Foundation passes the selected
connection policy to DBLayer; it does not normalize SQL or maintain a second
schema grammar.

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
CacheLayer lock provider; the backend may be file, Redis, Valkey, Memcached,
PDO, or SQLite according to CacheLayer configuration.

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

`db:table` reports columns, indexes, and foreign keys using read-only metadata
queries for DBLayer's SQLite, MySQL/MariaDB, and PostgreSQL drivers. Identifiers
are validated before interpolation. These commands connect to the database only
when selected and do not participate in web bootstrap.

## Testing

```php
$db = $app->testing()->database();

$result = $db->transaction(function () use ($app) {
    return $app->db()->repository('accounts')->create([...]);
});

$db->refresh();
```

`transaction()` always rolls back in `finally`. `refresh()` explicitly
authorizes DBLayer's destructive refresh and reruns the registered migrations.
