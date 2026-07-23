<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\ReqShield\Support\ValidationContext;
use Infocyph\ReqShield\Validator as ReqShieldValidator;
use Infocyph\Webrick\Request\Request;

final class FoundationValidationUserData
{
    public string $email = '';

    public int $age = 0;

    /**
     * @var array<string, mixed>
     */
    public array $profile = [];
}

it('exposes reqshield runtime features through the foundation validator manager', function (): void {
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
                'users.store' => [
                    'strip_unknown' => true,
                ],
                'users.strict' => [
                    'strict' => true,
                ],
                'users.dto' => [
                    'dto' => FoundationValidationUserData::class,
                ],
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
                'users.strict' => [
                    'email' => 'required|email',
                ],
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

    $manager = $app->validator();
    $request = Request::fake(
        post: [
            'email' => '  ADA@EXAMPLE.COM  ',
            'age' => '21',
            'profile' => [
                'name' => 'Ada',
            ],
            'extra' => 'discard-me',
        ],
    );

    $result = $manager->validateRequest('users.store', $request);

    expect($result->fails())->toBeFalse();
    expect($result->typed())->toBe([
        'email' => 'ada@example.com',
        'age' => 21,
        'profile.name' => 'Ada',
    ]);

    $dtoResult = $manager->validate('users.dto', [
        'email' => 'ada@example.com',
        'age' => '21',
    ]);

    expect($dtoResult->toDTO())->toBeInstanceOf(FoundationValidationUserData::class);

    $schema = $manager->exportSchema('users.store', 'introspection');

    expect($schema['email'])->toMatchArray([
        'sanitizers' => ['trim', 'lowercase'],
    ]);
    expect($schema['age'])->toMatchArray([
        'cast' => 'integer',
    ]);

    $strict = $manager->validateRequest('users.strict', Request::fake(
        post: [
            'email' => 'ada@example.com',
            'extra' => 'not-allowed',
        ],
    ));

    expect($strict->fails())->toBeTrue();
    expect($strict->errors())->toHaveKey('extra');

    $validator = $manager->validator('users.store')
        ->after(function (ValidationContext $context): void {
            if ($context->get('email') === 'blocked@example.com') {
                $context->addError('email', 'Blocked sender.');
            }
        });

    expect($validator)->toBeInstanceOf(ReqShieldValidator::class);
    expect($validator->validate([
        'email' => 'blocked@example.com',
        'age' => 21,
        'profile' => [
            'name' => 'Ada',
        ],
    ])->fails())->toBeTrue();
});
