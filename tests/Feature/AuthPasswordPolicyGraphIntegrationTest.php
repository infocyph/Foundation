<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Support\BaselinePasswordPolicy;
use Infocyph\Foundation\Foundation;

it('registers configured password policy without resolving a development container during contribution', function (): void {
    $app = Foundation::cli([
        'app' => ['capabilities' => ['auth']],
        'auth' => [
            'password_policy' => [
                'min_length' => 14,
                'max_length' => 16,
            ],
        ],
    ]);

    try {
        $policy = $app->make(PasswordPolicyInterface::class);
        expect($policy)->toBeInstanceOf(BaselinePasswordPolicy::class)
            ->and($policy->validate('abcdefghijklm')->valid)->toBeFalse()
            ->and($policy->validate('abcdefghijklmn')->valid)->toBeTrue()
            ->and($policy->validate('abcdefghijklmnopq')->valid)->toBeFalse();
    } finally {
        $app->container()->unset();
    }
});
