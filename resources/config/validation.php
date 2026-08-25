<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Execution
    |--------------------------------------------------------------------------
    |
    | Foundation resolves named application schemas and then hands execution to
    | ReqShield. "fail_fast" selects ReqShield's rule-level fail-fast behavior.
    |
    */
    'fail_fast' => true,

    /*
    |--------------------------------------------------------------------------
    | Database Validation
    |--------------------------------------------------------------------------
    |
    | ReqShield database rules use the configured DBLayer connection lazily.
    | Null selects Foundation's default database connection. Applications that
    | never use database validation do not open a database connection.
    |
    */
    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Validation Profile
    |--------------------------------------------------------------------------
    |
    | "allow_unknown" permits fields absent from the schema. "strict" rejects
    | unknown fields and "strip_unknown" removes them instead. "nested" enables
    | ReqShield nested-field flattening; nested_mode accepts `all|required`
    | (`required` maps to ReqShield's targeted mode).
    |
    | Messages, aliases, sanitizers, casts, locale packs and DTO handling are
    | native ReqShield features. Limits expose ReqShield's input safety bounds.
    |
    */
    'defaults' => [
        'allow_unknown' => true,
        'strip_unknown' => false,
        'strict' => false,
        'nested' => false,
        'nested_mode' => 'all',
        'throw_on_failure' => false,
        'locale' => null,
        'locale_packs' => [],
        'messages' => [],
        'aliases' => [],
        'sanitizers' => [],
        'casts' => [],
        'dto' => null,
        'limits' => [
            'max_depth' => 32,
            'max_fields' => 10_000,
            'max_wildcard_expansions' => 10_000,
            'max_flattened_paths' => 10_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Named Application Schemas
    |--------------------------------------------------------------------------
    |
    | Foundation ships its authentication request schemas internally. Add host
    | application schemas here. `extend` overlays fields onto an existing named
    | schema without replacing the complete definition.
    |
    */
    'schemas' => [],
    'extend' => [],

    /*
    |--------------------------------------------------------------------------
    | Schema Profile Overrides
    |--------------------------------------------------------------------------
    |
    | Override the default validation profile for individual named schemas.
    | Example: `['auth.login' => ['strict' => true]]`. Nested map options such
    | as messages, aliases, casts, sanitizers, locale packs and limits merge
    | with their defaults instead of replacing the full map.
    |
    */
    'overrides' => [],
];
