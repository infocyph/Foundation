<?php

declare(strict_types=1);

use Infocyph\ArrayKit\DTO\Concerns\DTOTrait;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Facades\Data;
use Infocyph\Foundation\Foundation;

final class ArrayKitProfileAddressDto
{
    use DTOTrait;

    public string $city = '';
}

final class ArrayKitProfileDto
{
    use DTOTrait;

    public ArrayKitProfileAddressDto $address;

    public int $age = 0;

    public string $name = '';
}

it('extends foundation config repository with arraykit config capabilities', function (): void {
    $repository = new ConfigRepository([
        'app' => [
            'env' => 'local',
            'name' => 'Infbyte',
        ],
    ]);

    expect($repository->getString('app.name'))->toBe('Infbyte')
        ->and($repository->getBool('app.debug', false))->toBeFalse()
        ->and($repository->snapshot())->toBeTrue();

    $repository->set('app.name', 'Infbyte Next');

    expect($repository->changed())->toBeTrue()
        ->and($repository->restore())->toBeTrue()
        ->and($repository->getString('app.name'))->toBe('Infbyte');

    $cachePath = sys_get_temp_dir() . '/foundation-config-cache-' . uniqid('', true) . '.php';

    expect($repository->exportCache($cachePath))->toBeTrue();

    $cached = new ConfigRepository();

    expect($cached->loadCache($cachePath))->toBeTrue()
        ->and($cached->getString('app.name'))->toBe('Infbyte');
});

it('exposes arraykit collections dto hydration and lazy config caching through foundation', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-arraykit-' . uniqid('', true);
    mkdir($basePath . '/config', 0775, true);
    mkdir($basePath . '/cache', 0775, true);

    file_put_contents($basePath . '/config/data.php', <<<'PHP'
<?php

return [
    'answer' => 42,
    'profile' => [
        'name' => 'Ada',
    ],
];
PHP);

    $app = Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'paths' => [
            'cache' => 'cache',
            'config' => 'config',
        ],
    ])->boot();

    $collection = $app->data()
        ->collection([
            ['name' => 'Ada', 'score' => 10],
            ['name' => 'Linus', 'score' => 25],
        ])
        ->process()
        ->where('score', '>=', 20)
        ->values()
        ->all();

    expect($collection)->toBe([
        ['name' => 'Linus', 'score' => 25],
    ]);

    $dto = $app->data()->dto(
        values: [
            'name' => 'Ada',
            'age' => '36',
            'address' => [
                'city' => 'Dhaka',
            ],
        ],
        class: ArrayKitProfileDto::class,
        nested: true,
        coerce: true,
    );

    expect($dto)->toBeInstanceOf(ArrayKitProfileDto::class)
        ->and($dto->age)->toBe(36)
        ->and($dto->address->city)->toBe('Dhaka');

    expect(Data::dot()->get([
        'profile' => [
            'name' => 'Ada',
        ],
    ], 'profile.name'))->toBe('Ada');

    $lazyConfig = Data::lazyConfig();
    $lazyConfig->warmNamespaceCache('data');

    expect($lazyConfig->get('data.answer'))->toBe(42)
        ->and($lazyConfig->namespaceCacheDirectory())->toBe($basePath . '/cache/config')
        ->and($basePath . '/cache/config/data.php')->toBeFile()
        ->and($basePath . '/cache/config/__flat.php')->toBeFile();
});
