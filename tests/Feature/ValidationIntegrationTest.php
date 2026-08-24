<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\ValidatorFactory;
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

it('exposes ReqShield 3.0.2 runtime features through the thin Foundation validator factory', function (): void {
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
