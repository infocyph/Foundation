# Modules

Modules install existing packages and publish Foundation configuration. They do
not create Foundation-prefixed bridge packages.

```bash
php infbyte module:list
php infbyte module:install db
php infbyte module:remove db
```

Built-in `logging`, `messaging`, `resources`, and `session` modules publish
configuration without Composer. Package modules run `composer require` or
`composer remove` with transitive dependency updates and `--update-no-dev`, so
production module changes do not resolve or install the application's
development toolchain. Configuration files are never overwritten or removed.

## Third-party manifest

A package may opt in by placing `foundation-module.php` at its Composer install
root:

```php
<?php

return [
    'reports' => [
        'description' => 'Reporting integration.',
        'aliases' => ['reporting'],
        'config' => [
            'reports.php' => 'resources/config/reports.php',
        ],
    ],
];
```

Module names and aliases use lowercase letters, digits, and hyphens. Config
targets are simple `.php` filenames and sources are safe package-relative
paths. Curated names and aliases are reserved. Collisions or unsafe paths fail
optimization.

`php infbyte optimize` scans Composer's installed-package metadata once and
writes `bootstrap/cache/modules.php`. Requests and ordinary commands only read
that compiled file; they never scan installed packages.
