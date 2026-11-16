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

/**
 * Read a generated array through the same captured-byte trust boundary as
 * runtime artifacts; the caller still asserts the fixture's concrete schema.
 *
 * @return array<string,mixed>
 * @throws RuntimeException
 * @throws UnexpectedValueException
 * @throws \InvalidArgumentException
 */
function foundationReadPhpFixtureArray(string $path): array
{
    $path = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::path($path);
    $sha256 = hash_file('sha256', $path);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to fingerprint generated PHP fixture.');
    }

    return \Infocyph\Foundation\Release\FoundationReleaseManifest::loadTrustedArray($path, $sha256, 'generated test fixture');
}

/**
 * Resolve a native service through PSR's mixed return boundary and verify that
 * the first-party composition supplied the requested contract.
 *
 * @template T of object
 * @param class-string<T> $class
 * @return T
 * @throws \Psr\Container\ContainerExceptionInterface
 * @throws \Psr\Container\NotFoundExceptionInterface
 * @throws UnexpectedValueException
 */
function foundationService(\Psr\Container\ContainerInterface $container, string $class): object
{
    $service = $container->get($class);
    if (!$service instanceof $class) {
        throw new UnexpectedValueException('Container did not provide ' . $class . '.');
    }

    return $service;
}

/**
 * Validate object-shaped JSON and fixture metadata before using map contracts.
 * Numeric keys are rejected rather than cast or silently discarded.
 *
 * @return array<string,mixed>
 * @throws UnexpectedValueException
 */
function foundationStringMap(mixed $value): array
{
    if (!is_array($value)) {
        throw new UnexpectedValueException('Expected a fixture map.');
    }
    $map = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new UnexpectedValueException('Fixture maps require string keys.');
        }
        $map[$key] = $item;
    }

    return $map;
}

/** @throws UnexpectedValueException */
function foundationFixtureString(mixed $value): string
{
    if (!is_string($value)) {
        throw new UnexpectedValueException('Expected a fixture string.');
    }

    return $value;
}

/** @throws UnexpectedValueException */
function foundationFixtureInteger(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) === 1
        && (string) (int) $value === $value
    ) {
        return (int) $value;
    }

    throw new UnexpectedValueException('Expected an integer fixture counter.');
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 * @throws UnexpectedValueException
 */
function foundationFixtureObject(mixed $value, string $class): object
{
    if (!$value instanceof $class) {
        throw new UnexpectedValueException('Expected a fixture instance of ' . $class . '.');
    }

    return $value;
}
