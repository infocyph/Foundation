<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Foundation;

$root = dirname(__DIR__, 2);
$loader = require $root . '/vendor/autoload.php';
if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Composer autoloader did not return ClassLoader.');
}

$baseConfig = [
    'base_path' => $root,
    '_config_cache' => false,
    'app' => [
        'base_path' => $root,
        'env' => 'testing',
    ],
];

$optionalMarkers = [
    'cache' => 'Infocyph\\CacheLayer\\Cache\\Cache',
    'database' => 'Infocyph\\DBLayer\\DB',
    'communication' => 'Infocyph\\TalkingBytes\\Http\\HttpClient',
    'filesystem' => 'Infocyph\\Pathwise\\PathwiseFacade',
    'messaging' => 'Infocyph\\Omnibus\\MessageBus',
    'security' => 'Infocyph\\Epicrypt\\Crypto\\AeadCipher',
    'validation' => 'Infocyph\\ReqShield\\Validator',
    'otp' => 'Infocyph\\OTP\\TOTP',
    'webauthn' => 'Webauthn\\PublicKeyCredential',
];

$loadedBeforeIsolation = [];
foreach ($optionalMarkers as $name => $class) {
    $loadedBeforeIsolation[$name] = class_exists($class, false) || interface_exists($class, false);
}

$optionalPrefixes = [
    'Infocyph\\CacheLayer\\',
    'Infocyph\\DBLayer\\',
    'Infocyph\\Epicrypt\\',
    'Infocyph\\Omnibus\\',
    'Infocyph\\OTP\\',
    'Infocyph\\Pathwise\\',
    'Infocyph\\ReqShield\\',
    'Infocyph\\TalkingBytes\\',
    'Webauthn\\',
];
$classMap = $loader->getClassMap();
foreach (array_keys($classMap) as $class) {
    if (array_any($optionalPrefixes, static fn(string $prefix): bool => str_starts_with($class, $prefix))) {
        unset($classMap[$class]);
    }
}
$classMapProperty = new ReflectionProperty($loader, 'classMap');
$classMapProperty->setValue($loader, $classMap);
foreach ($optionalPrefixes as $prefix) {
    $loader->setPsr4($prefix, []);
}

$probe = [
    'warm_base_path' => $root,
    'loaded_before_isolation' => $loadedBeforeIsolation,
    'base' => [],
    'services' => [],
    'auth' => [],
];

try {
    $isolated = Foundation::cli($baseConfig)->boot();
    $probe['base'] = [
        'created_and_booted' => true,
        'base_path' => $isolated->basePath(),
    ];

    $services = [
        'foundation.cache' => ['package' => 'infocyph/cachelayer', 'module' => 'cache'],
        'foundation.communication' => ['package' => 'infocyph/talkingbytes', 'module' => 'communication'],
        'foundation.db' => ['package' => 'infocyph/dblayer', 'module' => 'database'],
        'foundation.filesystem' => ['package' => 'infocyph/pathwise', 'module' => 'filesystem'],
        'foundation.messaging' => ['package' => 'infocyph/omnibus ^2.5', 'module' => 'messaging'],
        'foundation.security' => ['package' => 'infocyph/epicrypt', 'module' => 'security'],
        'foundation.validator' => ['package' => 'infocyph/reqshield', 'module' => 'validation'],
    ];

    foreach ($services as $service => $expected) {
        $message = null;
        try {
            $isolated->make($service);
        } catch (ServiceResolutionException $failure) {
            $message = $failure->getMessage();
        }

        $probe['services'][$service] = [
            'has' => $isolated->has($service),
            'message' => $message,
            'expected_package' => $expected['package'],
            'expected_module' => $expected['module'],
        ];
    }

    $defaultAuth = Foundation::cli($baseConfig);
    $defaultError = null;
    try {
        $defaultAuth->make(AuthManager::class);
    } catch (Throwable $failure) {
        $defaultError = $failure->getMessage();
    }
    $probe['auth']['default'] = [
        'resolved' => $defaultError === null,
        'message' => $defaultError,
    ];

    $otpConfig = $baseConfig;
    $otpConfig['auth'] = ['drivers' => ['mfa' => 'otp', 'passkey' => 'memory']];
    $otpError = null;
    try {
        Foundation::cli($otpConfig)->make(AuthManager::class);
    } catch (Throwable $failure) {
        $otpError = $failure->getMessage();
    }
    $probe['auth']['otp'] = ['message' => $otpError];

    $webauthnConfig = $baseConfig;
    $webauthnConfig['auth'] = ['drivers' => ['mfa' => 'simple', 'passkey' => 'webauthn']];
    $webauthnError = null;
    try {
        Foundation::cli($webauthnConfig)->make(AuthManager::class);
    } catch (Throwable $failure) {
        $webauthnError = $failure->getMessage();
    }
    $probe['auth']['webauthn'] = ['message' => $webauthnError];
} catch (Throwable $failure) {
    $probe['fatal'] = [
        'type' => $failure::class,
        'message' => $failure->getMessage(),
    ];
}

file_put_contents(
    'php://stdout',
    json_encode($probe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);
