<?php

declare(strict_types=1);

$foundationTestMfaKeyEnvironment = 'FOUNDATION_TEST_MFA_PROTECTION_KEY';
$foundationTestEnvironment = [
    'AUTH_OTP_RECOVERY_HMAC_KEY' => str_repeat('r', 32),
    $foundationTestMfaKeyEnvironment => rtrim(strtr(base64_encode(str_repeat('m', 32)), '+/', '-_'), '='),
    'AUTH_OTP_SECRET_PROTECTION_KEYS' => json_encode([[
        'id' => 'foundation-test-mfa',
        'environment' => $foundationTestMfaKeyEnvironment,
        'status' => 'active',
    ]], JSON_THROW_ON_ERROR),
];
foreach ($foundationTestEnvironment as $name => $value) {
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}
unset($foundationTestMfaKeyEnvironment, $foundationTestEnvironment, $name, $value);

/** Restore Webrick's process registries after an in-process production-runtime test. */
function foundationResetWebrickProductionRegistries(): void
{
    foreach ([
        \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::class,
        \Infocyph\Webrick\Router\Url\UrlGeneratorRegistry::class,
        \Infocyph\Webrick\Router\Constraint\Registry::class,
        \Infocyph\Webrick\Response\Headers\HeaderPolicy::class,
    ] as $registry) {
        $frozen = new ReflectionProperty($registry, 'frozen');
        $frozen->setValue(null, false);
    }

    \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::reset();
    \Infocyph\Webrick\Router\Facade\Router::reset();
}
