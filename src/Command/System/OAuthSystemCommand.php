<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Command\ExitCode;

final class OAuthSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        $this->assertOAuthEnabled();

        return match ($this->canonicalName()) {
            'auth:oauth:client:create' => $this->clientCreate(),
            'auth:oauth:client:list' => $this->clientList(),
            'auth:oauth:client:show' => $this->clientShow(),
            'auth:oauth:client:rotate-secret' => $this->clientRotateSecret(),
            'auth:oauth:client:enable' => $this->clientSetEnabled(true),
            'auth:oauth:client:disable' => $this->clientSetEnabled(false),
            'auth:oauth:authorization:list' => $this->authorizationList(),
            'auth:oauth:authorization:revoke' => $this->authorizationRevoke(),
            'auth:oauth:key:check' => $this->keyCheck(),
            default => throw new \LogicException('Unsupported OAuth system command.'),
        };
    }

    private function assertOAuthEnabled(): void
    {
        if ($this->application->config()->get('auth.oauth.enabled', false) !== true) {
            throw new \LogicException('OAuth 2.1 support is disabled; set auth.oauth.enabled=true before using OAuth administration commands.');
        }
    }

    private function clientCreate(): int
    {
        $type = OAuthClientType::tryFrom($this->option('type', OAuthClientType::Confidential->value) ?? '');
        if (!$type instanceof OAuthClientType) {
            throw new \InvalidArgumentException('--type must be public or confidential.');
        }

        $grants = array_map($this->grant(...), $this->optionValues('grant'));
        if ($grants === []) {
            throw new \InvalidArgumentException('At least one --grant option is required.');
        }

        $registration = $this->clients()->register(
            type: $type,
            grants: $grants,
            redirectUris: $this->optionValues('redirect-uri'),
            scopes: $this->optionValues('scope'),
            audiences: $this->optionValues('audience'),
            metadata: $this->flag('native-client') ? ['native_client' => true] : [],
        );

        $data = [
            'client' => $this->clientData($registration->client),
            'redirect_uris' => $this->clients()->redirectUris($registration->client->clientId),
            'scopes' => $this->clients()->scopes($registration->client->clientId),
            'client_secret' => $registration->secret,
            'secret_display' => $registration->secret === null ? 'none' : 'one-time',
        ];

        return $this->emit(
            $data,
            $registration->secret === null
                ? sprintf('OAuth public client created: %s', $registration->client->clientId)
                : sprintf('OAuth confidential client created: %s. The returned client secret is shown once.', $registration->client->clientId),
        );
    }

    private function clientList(): int
    {
        $clients = array_map(
            $this->clientData(...),
            $this->clientStore()->list($this->positiveLimit()),
        );

        return $this->emit(['clients' => $clients, 'count' => count($clients)]);
    }

    private function clientShow(): int
    {
        $clientId = $this->requiredArgument();
        $client = $this->clientStore()->find($clientId);
        if (!$client instanceof OAuthClient) {
            $this->io()->error(sprintf('OAuth client "%s" was not found.', $clientId));

            return ExitCode::FAILURE;
        }

        return $this->emit([
            'client' => $this->clientData($client),
            'redirect_uris' => $this->clientStore()->redirectUris($clientId),
            'scopes' => $this->clientStore()->scopes($clientId),
        ]);
    }

    private function clientRotateSecret(): int
    {
        $clientId = $this->requiredArgument();
        $secret = $this->clients()->rotateSecret($clientId);

        return $this->emit(
            ['client_id' => $clientId, 'client_secret' => $secret, 'secret_display' => 'one-time'],
            sprintf('OAuth client secret rotated for %s. The returned secret is shown once.', $clientId),
        );
    }

    private function clientSetEnabled(bool $enabled): int
    {
        $clientId = $this->requiredArgument();
        if (!$this->clients()->setEnabled($clientId, $enabled)) {
            $this->io()->error(sprintf('OAuth client "%s" was not found.', $clientId));

            return ExitCode::FAILURE;
        }

        return $this->emit(
            ['client_id' => $clientId, 'enabled' => $enabled],
            sprintf('OAuth client %s: %s', $enabled ? 'enabled' : 'disabled', $clientId),
        );
    }

    private function authorizationList(): int
    {
        $authorizations = array_map(
            $this->authorizationData(...),
            $this->authorizationStore()->list($this->positiveLimit(), $this->option('client')),
        );

        return $this->emit(['authorizations' => $authorizations, 'count' => count($authorizations)]);
    }

    private function authorizationRevoke(): int
    {
        $authorizationId = $this->requiredArgument();
        $authorization = $this->authorizationStore()->find($authorizationId);
        if (!$authorization instanceof OAuthAuthorization) {
            $this->io()->error(sprintf('OAuth authorization "%s" was not found.', $authorizationId));

            return ExitCode::FAILURE;
        }
        if (!$this->authorize(sprintf('Revoke OAuth authorization "%s"?', $authorizationId))) {
            return ExitCode::FAILURE;
        }

        $changed = $this->authorizationStore()->revoke($authorizationId, $this->clock()->now());
        if ($changed) {
            $this->auditor()->record(
                AuthEventType::OAUTH_AUTHORIZATION_REVOKED,
                $authorization->accountId,
                metadata: [
                    'client_id' => $authorization->clientId,
                    'authorization_id' => $authorization->id,
                    'result' => 'revoked',
                ],
            );
        }

        return $this->emit(
            ['authorization_id' => $authorizationId, 'revoked' => true, 'changed' => $changed],
            $changed ? 'OAuth authorization revoked.' : 'OAuth authorization was already revoked.',
        );
    }

    private function keyCheck(): int
    {
        try {
            $keys = $this->application->make(OAuthSigningKeySet::class);
            $jwks = $this->application->make(JwkSetProviderInterface::class)->jwks();
            $data = [
                'ready' => true,
                'issuer' => $keys->issuer,
                'active_key_id' => $keys->activeKeyId,
                'algorithm' => $keys->algorithm->value,
                'public_key_count' => count($jwks['keys']),
            ];
            $this->auditor()->record(AuthEventType::OAUTH_KEY_READINESS, null, metadata: [
                'key_id' => $keys->activeKeyId,
                'algorithm' => $keys->algorithm->value,
                'result' => 'ready',
            ]);

            return $this->emit($data, 'OAuth signing keys are ready.');
        } catch (\Throwable $exception) {
            try {
                $this->auditor()->record(AuthEventType::OAUTH_KEY_READINESS, null, metadata: [
                    'result' => 'failed',
                    'reason' => $exception::class,
                ]);
            } catch (\Throwable) {
                // Readiness must not be replaced by an audit backend failure.
            }

            throw new \RuntimeException('OAuth signing-key readiness check failed.', previous: $exception);
        }
    }

    private function authorize(string $question): bool
    {
        if ($this->flag('force')) {
            return true;
        }
        if (!$this->io()->interactive()) {
            $this->io()->error('This destructive operation requires --force in non-interactive mode.');

            return false;
        }

        return $this->io()->confirm($question, false);
    }

    /** @return array<string, mixed> */
    private function clientData(OAuthClient $client): array
    {
        return [
            'client_id' => $client->clientId,
            'type' => $client->type->value,
            'authentication_method' => $client->authenticationMethod->value,
            'grants' => array_map(static fn(OAuthGrantType $grant): string => $grant->value, $client->grants),
            'audiences' => $client->audiences,
            'enabled' => $client->enabled && $client->disabledAt === null,
            'created_at' => $client->createdAt,
            'updated_at' => $client->updatedAt,
            'disabled_at' => $client->disabledAt,
            'metadata' => $client->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function authorizationData(OAuthAuthorization $authorization): array
    {
        return [
            'authorization_id' => $authorization->id,
            'client_id' => $authorization->clientId,
            'account_id' => $authorization->accountId,
            'scopes' => $authorization->scopes,
            'audiences' => $authorization->audiences,
            'created_at' => $authorization->createdAt,
            'expires_at' => $authorization->expiresAt,
            'revoked_at' => $authorization->revokedAt,
        ];
    }

    private function grant(string $value): OAuthGrantType
    {
        return OAuthGrantType::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf('Unsupported OAuth grant "%s".', $value));
    }

    private function positiveLimit(): int
    {
        $value = $this->option('limit');
        if ($value === null) {
            return 100;
        }
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1 || (int) $value > 500) {
            throw new \InvalidArgumentException('--limit must be an integer between 1 and 500.');
        }

        return (int) $value;
    }

    private function requiredArgument(): string
    {
        return $this->argument(0) ?? throw new \LogicException('Validated OAuth command argument is unavailable.');
    }

    private function clients(): OAuthClientManager
    {
        return $this->application->make(OAuthClientManager::class);
    }

    private function clientStore(): OAuthClientStoreInterface
    {
        return $this->application->make(OAuthClientStoreInterface::class);
    }

    private function authorizationStore(): OAuthAuthorizationStoreInterface
    {
        return $this->application->make(OAuthAuthorizationStoreInterface::class);
    }

    private function clock(): ClockInterface
    {
        return $this->application->make(ClockInterface::class);
    }

    private function auditor(): OAuthAuditRecorder
    {
        return $this->application->make(OAuthAuditRecorder::class);
    }
}
