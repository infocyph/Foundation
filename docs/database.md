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
