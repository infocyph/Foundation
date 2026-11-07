<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleActivationManager;
use Infocyph\Foundation\Module\ModuleFeatureCommandSnapshot;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;

$directory = $argv[1];
$app = Foundation::cli(['base_path' => $directory, '_config_cache' => false]);
$context = new ModuleFeatureCommandSnapshot($app)->capture();
touch($directory . '/ready');
AuthContentionFixture::waitFor($directory . '/start');
try {
    if (($argv[2] ?? '') === 'remove') {
        $removed = new \Infocyph\Foundation\Module\ModuleFeatureComposerExecutor($app)->applyPackages('remove', ['data.database'], $context);
        if (!$removed->successful()) {
            throw new RuntimeException('Composer removal failed: ' . $removed->stderr);
        }
    } else {
        new ModuleActivationManager($app)->applyFeatures('enable', ['data.database'], $context);
    }
    $result = ['published' => true];
} catch (Throwable $failure) {
    $result = ['rejected' => $failure->getMessage()];
}
file_put_contents($directory . '/activation-result.json', json_encode($result, JSON_THROW_ON_ERROR));
