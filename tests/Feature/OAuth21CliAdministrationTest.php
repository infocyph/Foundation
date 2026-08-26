<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Command\CommandCatalog;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Command\System\OAuthSystemCommand;

final class OAuth21CliTestIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $payloads = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);
        throw new LogicException('Choice input is not expected.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);
        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void
    {
        unset($message);
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->payloads[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        unset($question);
        throw new LogicException('Password input is not expected.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question);
        return $default ?? '';
    }

    public function success(string $message): void
    {
        unset($message);
    }

    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    public function warning(string $message): void
    {
        unset($message);
    }

    public function write(string $message): void
    {
        unset($message);
    }

    public function writeln(string $message = ''): void
    {
        unset($message);
    }
}

it('registers the bounded OAuth administration command surface on the system handler', function (): void {
    $catalog = new CommandCatalog();
    $descriptors = $catalog->descriptors();
    $commands = [
        'auth:oauth:client:create',
        'auth:oauth:client:list',
        'auth:oauth:client:show',
        'auth:oauth:client:rotate-secret',
        'auth:oauth:client:enable',
        'auth:oauth:client:disable',
        'auth:oauth:authorization:list',
        'auth:oauth:authorization:revoke',
        'auth:oauth:key:check',
    ];

    foreach ($commands as $name) {
        expect($descriptors)->toHaveKey($name)
            ->and($descriptors[$name]->handler)->toBe(OAuthSystemCommand::class)
            ->and($descriptors[$name]->system)->toBeTrue();
    }

    $create = $catalog->find('auth:oauth:client:create');
    expect($create)->not->toBeNull()
        ->and($create?->options()['grant']['multiple'])->toBeTrue()
        ->and($create?->options()['redirect-uri']['multiple'])->toBeTrue()
        ->and($create?->options()['scope']['multiple'])->toBeTrue()
        ->and($create?->options()['audience']['multiple'])->toBeTrue()
        ->and($catalog->find('auth:oauth:client:list')?->options())->toHaveKey('limit')
        ->and($catalog->find('auth:oauth:authorization:list')?->options())->toHaveKeys(['limit', 'client'])
        ->and($catalog->find('auth:oauth:authorization:revoke')?->options())->toHaveKey('force');
});

it('fails OAuth administration before resolving OAuth services when the feature is disabled', function (): void {
    $io = new OAuth21CliTestIO();
    $dispatcher = new CommandDispatcher(
        [
            'base_path' => getcwd(),
            'auth' => ['oauth' => ['enabled' => false]],
        ],
        new CommandRegistry(),
    );

    $exit = $dispatcher->run(['infbyte', 'auth:oauth:client:list'], $io);

    expect($exit)->toBe(ExitCode::FAILURE)
        ->and(implode("\n", $io->errors))->toContain('OAuth 2.1 support is disabled');
});

it('never serializes stored client secret hashes through the OAuth CLI client view', function (): void {
    $application = Application::create([
        'base_path' => getcwd(),
        'auth' => ['oauth' => ['enabled' => false]],
    ], RuntimeMode::Cli);
    $command = new OAuthSystemCommand($application);
    $client = new OAuthClient(
        id: 'internal-id',
        clientId: 'oc_public-id',
        type: OAuthClientType::Confidential,
        authenticationMethod: OAuthClientAuthenticationMethod::ClientSecretBasic,
        secretHash: 'stored-secret-hash-must-never-be-output',
        grants: [OAuthGrantType::ClientCredentials],
        audiences: ['https://api.example.test'],
        enabled: true,
        createdAt: 100,
        updatedAt: 100,
        metadata: ['owner' => 'test'],
    );

    $method = new ReflectionMethod(OAuthSystemCommand::class, 'clientData');
    /** @var array<string, mixed> $data */
    $data = $method->invoke($command, $client);
    $encoded = json_encode($data, JSON_THROW_ON_ERROR);

    expect($data)->not->toHaveKey('secret_hash')
        ->and($data)->not->toHaveKey('id')
        ->and($encoded)->not->toContain('stored-secret-hash-must-never-be-output');
});
