<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Epicrypt\Token\Payload\PurposeTokenFailureReason;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Testing\FrozenClock;

it('keeps Foundation simple tokens purpose context time and root-rotation isolated', function (): void {
    $environment = 'FOUNDATION_TEST_SIMPLE_TOKEN_ROOT';
    $firstRoot = str_repeat('a', 64);
    $secondRoot = str_repeat('b', 64);
    $_ENV[$environment] = $firstRoot;
    $_SERVER[$environment] = $firstRoot;
    putenv($environment . '=' . $firstRoot);

    $clock = new FrozenClock(1_700_000_000);
    $config = new ConfigRepository([
        'auth' => [
            'token_secret_environment' => $environment,
        ],
    ]);
    $factory = new EpicryptPurposeTokenFactory(new AuthSecretResolver($config), $clock);

    try {
        $tokens = $factory->forPurpose('password_reset', 10);
        $token = $tokens->issue(['flow' => 'reset'], 'account-1');
        $verified = $tokens->verify($token);

        expect($verified->verified)->toBeTrue()
            ->and($verified->subjectId)->toBe('account-1')
            ->and($verified->claims)->toBe(['flow' => 'reset']);

        $wrongPurpose = $factory->forPurpose('email_verification', 10)->verify($token);
        expect($wrongPurpose->verified)->toBeFalse();

        $domain = 'foundation.auth.simple-token.password_reset.v1';
        $key = new KeyDeriver()->derivePurposeKeyBinary(
            $firstRoot,
            $domain,
            'foundation.auth.simple-token.v1',
            64,
        );
        $wrongContext = new PurposeToken(
            keys: $key,
            purpose: $domain,
            context: 'foundation.auth.simple-token.other-context.v1',
            ttlSeconds: 10,
            clock: new EpicryptClockAdapter($clock),
        );
        expect($wrongContext->verify($token)->failureReason)
            ->toBe(PurposeTokenFailureReason::WRONG_CONTEXT);

        $_ENV[$environment] = $secondRoot;
        $_SERVER[$environment] = $secondRoot;
        putenv($environment . '=' . $secondRoot);
        expect($factory->forPurpose('password_reset', 10)->verify($token)->verified)
            ->toBeFalse();

        $_ENV[$environment] = $firstRoot;
        $_SERVER[$environment] = $firstRoot;
        putenv($environment . '=' . $firstRoot);
        $expiring = $factory->forPurpose('password_reset', 10)->issue([], 'account-1');
        $clock->advance(11);
        $expired = $factory->forPurpose('password_reset', 10)->verify($expiring);
        expect($expired->verified)->toBeFalse()
            ->and($expired->failureReason)->toBe(PurposeTokenFailureReason::EXPIRED_TOKEN);
    } finally {
        unset($_ENV[$environment], $_SERVER[$environment]);
        putenv($environment);
    }
});
