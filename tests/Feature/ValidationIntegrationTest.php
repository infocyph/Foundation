<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Exceptions\FrozenSchemaRegistryException;
use Infocyph\ReqShield\Exceptions\InputLimitException;
use Infocyph\ReqShield\Schema\SchemaRegistry;
use Infocyph\ReqShield\Support\ValidationContext;
use Infocyph\ReqShield\Validator as ReqShieldValidator;

final class FoundationValidationUserData
{
    public string $email = '';
    public int $age = 0;
    /** @var array<string,mixed> */
    public array $profile = [];
}

it('accepts validation policy only from documented defaults and schema overrides', function (): void {
    $app = Foundation::web([
        'validation' => [
            'schemas' => [
                'users.store' => ['email' => 'required|email'],
            ],
        ],
    ])->boot();

    $result = $app->make(ValidatorFactory::class)->make('users.store')->validate([
        'email' => 'ada@example.com',
        'extra' => 'retained',
    ]);

    expect($result->fails())->toBeFalse()
        ->and($result->errors())->not->toHaveKey('extra');
});

it('exposes ReqShield 3.2 runtime features through the thin Foundation validator factory', function (): void {
    $app = Foundation::web([
        'validation' => [
            'defaults' => [
                'nested' => true,
                'nested_mode' => 'required',
                'messages' => [
                    'profile.name.required' => 'Profile Name is required.',
                ],
            ],
            'overrides' => [
                'users.store' => ['strip_unknown' => true],
                'users.strict' => ['strict' => true],
                'users.dto' => ['dto' => FoundationValidationUserData::class],
            ],
            'schemas' => [
                'users.store' => [
                    'email' => [
                        'rules' => 'required|email',
                        'sanitize' => ['trim', 'lowercase'],
                    ],
                    'age' => [
                        'rules' => 'required|integer|min:18',
                        'cast' => 'integer',
                    ],
                    'profile.name' => [
                        'rules' => 'required|string|min:3',
                        'alias' => 'Profile Name',
                    ],
                ],
                'users.strict' => ['email' => 'required|email'],
                'users.dto' => [
                    'email' => 'required|email',
                    'age' => [
                        'rules' => 'required|integer|min:18',
                        'cast' => 'integer',
                    ],
                ],
            ],
        ],
    ])->boot();

    $factory = $app->make(ValidatorFactory::class);
    $result = $factory->make('users.store')->validate([
        'email' => '  ADA@EXAMPLE.COM  ',
        'age' => '21',
        'profile' => ['name' => 'Ada'],
        'extra' => 'discard-me',
    ]);

    expect($result->fails())->toBeFalse()
        ->and($result->typed())->toBe([
            'email' => 'ada@example.com',
            'age' => 21,
            'profile.name' => 'Ada',
        ]);

    $dtoResult = $factory->make('users.dto')->validate([
        'email' => 'ada@example.com',
        'age' => '21',
    ]);
    expect($dtoResult->toDTO())->toBeInstanceOf(FoundationValidationUserData::class);

    $validator = $factory->make('users.store');
    $schema = $validator->exportSchema('introspection');
    expect($schema['email'])->toMatchArray(['sanitizers' => ['trim', 'lowercase']])
        ->and($schema['age'])->toMatchArray(['cast' => 'integer']);

    $strict = $factory->make('users.strict')->validate([
        'email' => 'ada@example.com',
        'extra' => 'not-allowed',
    ]);
    expect($strict->fails())->toBeTrue()
        ->and($strict->errors())->toHaveKey('extra');

    $after = $factory->make('users.store')
        ->after(function (ValidationContext $context): void {
            if ($context->get('email') === 'blocked@example.com') {
                $context->addError('email', 'Blocked sender.');
            }
        });

    expect($after)->toBeInstanceOf(ReqShieldValidator::class)
        ->and($after->validate([
            'email' => 'blocked@example.com',
            'age' => 21,
            'profile' => ['name' => 'Ada'],
        ])->fails())->toBeTrue();
});


it('freezes named validation schema topology at application composition', function (): void {
    $app = Foundation::web([
        'validation' => [
            'schemas' => [
                'users.profile' => [
                    'email' => 'required|email',
                ],
            ],
            'extend' => [
                'users.profile' => [
                    'name' => 'required|string|min:2',
                ],
            ],
        ],
    ])->boot();

    $registry = $app->make(SchemaRegistry::class);

    expect($registry->isFrozen())->toBeTrue()
        ->and($registry->schema('users.profile'))->toBe([
            'email' => 'required|email',
            'name' => 'required|string|min:2',
        ])
        ->and(fn() => $registry->define('runtime.schema', ['id' => 'required']))
        ->toThrow(FrozenSchemaRegistryException::class);
});

it('keeps non-database validation lazy and enforces ReqShield input bounds', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-validation-lazy-' . bin2hex(random_bytes(6));
    $databaseDirectory = $basePath . '/database';
    mkdir($databaseDirectory, 0775, true);
    $database = $databaseDirectory . '/unused.sqlite';

    try {
        $app = Foundation::web([
            'app' => ['base_path' => $basePath],
            'database' => [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'driver' => 'sqlite',
                        'database' => $database,
                    ],
                ],
            ],
            'validation' => [
                'schemas' => [
                    'users.email' => ['email' => 'required|email'],
                ],
            ],
        ])->boot();

        $factory = $app->make(ValidatorFactory::class);
        $result = $factory->make('users.email')->validate([
            'email' => 'ada@example.test',
        ]);

        expect($result->passes())->toBeTrue()
            ->and(is_file($database))->toBeFalse()
            ->and(fn() => $factory->makeRules(
                ['email' => 'required|email'],
                ['limits' => ['max_fields' => 1]],
            )->validate([
                'email' => 'ada@example.test',
                'extra' => 'bounded',
            ]))->toThrow(InputLimitException::class);
    } finally {
        if (is_file($database)) {
            unlink($database);
        }
        is_dir($databaseDirectory) && rmdir($databaseDirectory);
        is_dir($basePath) && rmdir($basePath);
    }
});

it('keeps frozen compiled validator state isolated across sequential and Fiber reuse', function (): void {
    $app = Foundation::web([
        'validation' => [
            'schemas' => [
                'users.email' => [
                    'email' => [
                        'rules' => 'required|email',
                        'sanitize' => ['trim', 'lowercase'],
                    ],
                ],
            ],
        ],
    ])->boot();

    $factory = $app->make(ValidatorFactory::class);
    $builder = $factory->make('users.email')->after(
        static function (ValidationContext $context): void {
            Fiber::suspend($context->get('email'));
        },
    );
    $compiled = new CompiledValidator($builder);

    $sequential = $factory->compile('users.email');
    expect($sequential->validate(['email' => ' FIRST@EXAMPLE.TEST '])->typed()['email'] ?? null)
        ->toBe('first@example.test')
        ->and($sequential->validate(['email' => ' SECOND@EXAMPLE.TEST '])->typed()['email'] ?? null)
        ->toBe('second@example.test');

    $fiberA = new Fiber(
        static fn(): array => $compiled->validate([
            'email' => ' A@EXAMPLE.TEST ',
        ])->typed(),
    );
    $fiberB = new Fiber(
        static fn(): array => $compiled->validate([
            'email' => ' B@EXAMPLE.TEST ',
        ])->typed(),
    );

    expect($fiberA->start())->toBe('a@example.test')
        ->and($fiberB->start())->toBe('b@example.test');

    $fiberA->resume();
    $fiberB->resume();

    expect($fiberA->getReturn()['email'] ?? null)->toBe('a@example.test')
        ->and($fiberB->getReturn()['email'] ?? null)->toBe('b@example.test');
});
