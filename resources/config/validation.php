<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Execution
    |--------------------------------------------------------------------------
    |
    | "fail_fast" stops at the first failed rule when true. Disable it when a
    | caller needs the complete set of validation failures in one pass.
    | Accepted values: `true|false`.
    |
    */
    'fail_fast' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Validation Profile
    |--------------------------------------------------------------------------
    |
    | "allow_unknown" permits fields absent from the schema; "strip_unknown"
    | removes them from validated output. "strict" disables permissive value
    | coercion. "nested" enables nested validation and "nested_mode" selects
    | how nested failures are aggregated. "throw_on_failure" chooses exceptions
    | instead of a failed result.
    |
    | "locale" selects messages and "locale_packs" supplies additional packs.
    | "messages" replaces rule messages, "aliases" gives fields display names,
    | "sanitizers" and "casts" define output transformations, and "dto" names
    | an optional output class. Empty collections retain library defaults.
    |
    | Boolean keys accept `true|false`. Nested mode accepts `all|required`.
    | A locale may be `en`. A Bengali locale pack may map its `required` key to
    | `এই ঘরটি আবশ্যক।`; a custom message may map `required` to
    | `This field is required.`; and an alias may map `email` to `email address`.
    | A DTO class may be `App\\Data\\SignupData`.
    |
    | Built-in sanitizers: `alpha|alphaDash|alphanumeric|alphanumericSpace|array|`
    | `base64Decode|base64Encode|boolean|camelCase|currency|domain|email|`
    | `escapeLike|filename|float|formatCurrency|htmlDecode|htmlEncode|integer|`
    | `jsonDecode|jsonEncode|kebabCase|lowercase|normalizeWhitespace|numeric|`
    | `pascalCase|phone|removeLineBreaks|removeSqlPatterns|removeXss|sentenceCase|`
    | `slug|snakeCase|string|stripTags|stripUnsafeTags|stripWhitespace|titleCase|`
    | `trim|truncate|truncateWords|uppercase|url`, or a callable.
    |
    | Built-in casts: `int|integer|float|double|real|bool|boolean|string|array|`
    | `object|date|datetime|datetimeimmutable`, an enum class, a sanitizer name,
    | or a callable. Map fields to names/pipelines, e.g. `['email' => ['trim',
    | 'lowercase']]` and `['age' => 'int']`.
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Overrides
    |--------------------------------------------------------------------------
    |
    | Add schema-name keys here to override the defaults for individual
    | validation schemas without changing their registered rule definitions.
    | Example: `['auth.login' => ['strict' => true]]`; accepted override keys
    | are the same keys documented in the default validation profile above.
    |
    */
    'overrides' => [],
];
