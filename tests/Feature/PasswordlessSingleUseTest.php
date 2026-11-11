<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessStatus;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Foundation;

it('redeems a passwordless link once without consuming other or invalid credentials', function (string $driver): void {
    $app = Foundation::web([
        'auth' => ['drivers' => ['tokens' => $driver], 'passwordless_ttl' => 60],
        'security' => ['jwt' => ['issuer' => 'passwordless.test', 'audience' => 'passwordless.test', 'leeway_seconds' => 0]],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $manager = $app->make(AuthServices::class)->passwordless();
        $first = $manager->issue('one@example.test')->token ?? '';
        $second = $manager->issue('one@example.test')->token ?? '';

        expect($first)->not->toBe('')->not->toBe($second)
            ->and($manager->verify($first . 'tampered')->status)->toBe(PasswordlessStatus::INVALID)
            ->and($manager->verify($first)->verification?->subjectId)->toBe('one@example.test')
            ->and($manager->verify($first)->status)->toBe(PasswordlessStatus::INVALID)
            ->and($manager->verify($second)->status)->toBe(PasswordlessStatus::VERIFIED);

        $unregistered = $app->make(PasswordlessTokenServiceInterface::class)->issue('one@example.test');
        expect($manager->verify($unregistered)->status)->toBe(PasswordlessStatus::INVALID);
    });
})->with(['simple', 'security']);

it('fails passwordless issuance and redemption when atomic state is unavailable', function (string $driver): void {
    $app = Foundation::web([
        'auth' => ['drivers' => ['tokens' => $driver]],
        'security' => ['jwt' => ['issuer' => 'passwordless.test', 'audience' => 'passwordless.test']],
    ]);
    $tokens = $app->make(PasswordlessTokenServiceInterface::class);
    $notifier = new CollectingAuthNotifier();
    $unavailable = new class implements TtlStoreInterface {
        public bool $unavailable = true;

        public function __construct(private ArrayTtlStore $store = new ArrayTtlStore()) {}

        #[\Override]
        public function delete(string $key): void
        {
            $this->store->delete($key);
        }

        #[\Override]
        public function get(string $key, mixed $default = null): mixed
        {
            return $this->store->get($key, $default);
        }

        /**
         * @throws \RuntimeException
         */
        #[\Override]
        public function pull(string $key, mixed $default = null): mixed
        {
            if ($this->unavailable) {
                throw new RuntimeException('Passwordless state unavailable.');
            }

            return $this->store->pull($key, $default);
        }

        /**
         * @throws \RuntimeException
         */
        #[\Override]
        public function put(string $key, mixed $value, int $ttlSeconds): void
        {
            if ($this->unavailable) {
                throw new RuntimeException('Passwordless state unavailable.');
            }

            $this->store->put($key, $value, $ttlSeconds);
        }
    };
    $manager = new PasswordlessManager($tokens, $notifier, $unavailable, 900);
    expect(fn() => $manager->issue('owner@example.test'))->toThrow(RuntimeException::class, 'state unavailable')
        ->and($notifier->notifications())->toBe([]);
    $unavailable->unavailable = false;
    $valid = $manager->issue('owner@example.test')->token ?? '';
    $unavailable->unavailable = true;
    expect(fn() => $manager->verify($valid))->toThrow(RuntimeException::class, 'state unavailable');
    $unavailable->unavailable = false;
    expect($manager->verify($valid)->successful())->toBeTrue()
        ->and($manager->verify($valid)->successful())->toBeFalse();
})->with(['simple', 'security']);

it('expires a passwordless link using the native token clock', function (string $driver): void {
    $clock = new \Infocyph\Foundation\Testing\FrozenClock(1_800_000_000);
    $secrets = new \Infocyph\Foundation\Auth\Internal\AuthSecretResolver(new \Infocyph\Foundation\Config\ConfigRepository());
    $tokens = $driver === 'simple'
        ? new \Infocyph\Foundation\Auth\Support\SimplePasswordlessTokenService(
            new \Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory($secrets, $clock), 30,
        )
        : new \Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordlessTokenService(
            new \Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptTokenFactory($secrets, $clock, 'passwordless.test', 'passwordless.test'), 30,
        );
    $manager = new \Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager(
        $tokens, new \Infocyph\Foundation\Auth\Support\CollectingAuthNotifier(),
        new \Infocyph\Foundation\Auth\Support\ArrayTtlStore($clock), 30,
    );
    $token = $manager->issue('expired@example.test')->token ?? '';
    $clock->advance(31);
    expect($manager->verify($token)->status)->toBe(PasswordlessStatus::EXPIRED)
        ->and($manager->verify($token)->successful())->toBeFalse();
})->with(['simple', 'security']);

it('redeems one shared passwordless link across independent application processes', function (string $driver): void {
    $directory = sys_get_temp_dir() . '/foundation-passwordless-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $configuration = [
        'app' => ['base_path' => $directory, 'env' => 'local'],
        'auth' => ['drivers' => ['tokens' => $driver, 'cache' => 'cache']],
        'cache' => [
            'default' => 'sqlite',
            'default_counter' => 'unused-redis',
            'counters' => ['unused-redis' => ['driver' => 'redis']],
            'prefix' => 'passwordless-test',
            'stores' => ['sqlite' => ['driver' => 'sqlite', 'file' => 'state.sqlite']],
        ],
        'security' => ['jwt' => ['issuer' => 'passwordless.test', 'audience' => 'passwordless.test', 'leeway_seconds' => 0]],
    ];
    file_put_contents($directory . '/configuration.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $processes = [];

    try {
        $app = Foundation::web($configuration);
        $app->container()->withinScope('webrick.request', static function () use ($app, $directory, $configuration, &$processes): void {
            $issuer = $app->make(AuthServices::class)->passwordless();
            $sequential = $issuer->issue('owner@example.test')->token ?? '';
            $other = Foundation::web($configuration);
            $other->container()->withinScope('webrick.request', static function () use ($other, $sequential): void {
                expect($other->make(AuthActions::class)->verifyPasswordless(['token' => $sequential])->successful())->toBeTrue();
            });
            expect($issuer->verify($sequential)->successful())->toBeFalse();

            $token = $issuer->issue('owner@example.test')->token ?? '';
            for ($index = 0; $index < 4; ++$index) {
                $process = proc_open([
                    PHP_BINARY, dirname(__DIR__) . '/Fixtures/passwordless_worker.php', $directory . '/configuration.json',
                ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start passwordless redemption worker.');
                }
                stream_set_timeout($pipes[1], 15);
                $processes[] = [$process, $pipes[0], $pipes[1], $pipes[2]];
            }
            foreach ($processes as [, , $stdout]) {
                expect(trim((string) fgets($stdout)))->toBe('READY');
            }
            foreach ($processes as [, $stdin]) {
                fwrite($stdin, $token . PHP_EOL);
                fflush($stdin);
            }
            $successes = 0;
            foreach ($processes as [, , $stdout, $stderr]) {
                $result = trim((string) fgets($stdout));
                expect($result)->toBeIn(['0', '1']);
                $successes += $result === '1' ? 1 : 0;
                expect(stream_get_contents($stderr))->toBe('');
            }
            expect($successes)->toBe(1)
                ->and($issuer->verify($token)->successful())->toBeFalse()
                ->and(file_get_contents($directory . '/state.sqlite'))->not->toContain($token, $sequential);
        });
    } finally {
        foreach ($processes as [$process, $stdin, $stdout, $stderr]) {
            fclose($stdin);
            fclose($stdout);
            fclose($stderr);
            expect(proc_close($process))->toBe(0);
        }
        $files = glob($directory . '/*');
        if ($files === false) {
            throw new RuntimeException('Unable to list passwordless fixture files.');
        }
        foreach ($files as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['simple', 'security']);
