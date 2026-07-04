<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\Support\Base64Url;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final readonly class WebAuthnPublicKeyOptionsFactory
{
    public function __construct(
        private WebAuthnConfig $config,
        private WebAuthnRuntime $runtime,
    ) {}

    /**
     * @param list<string> $allowedCredentialIds
     * @return array<string, mixed>
     */
    public function authentication(string $challenge, array $allowedCredentialIds = []): array
    {
        return $this->runtime->requestOptionsToArray(
            $this->authenticationOptions($challenge, $allowedCredentialIds),
        );
    }

    /**
     * @param list<string> $allowedCredentialIds
     */
    public function authenticationOptions(string $challenge, array $allowedCredentialIds = []): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            $challenge,
            rpId: $this->config->rpId,
            allowCredentials: $this->descriptors($allowedCredentialIds),
            userVerification: $this->config->userVerification,
            timeout: $this->timeout(),
        );
    }

    /**
     * @param list<string> $excludedCredentialIds
     * @return array<string, mixed>
     */
    public function registration(string $accountId, string $challenge, array $excludedCredentialIds = []): array
    {
        return $this->runtime->creationOptionsToArray(
            $this->registrationOptions($accountId, $challenge, $excludedCredentialIds),
        );
    }

    /**
     * @param list<string> $excludedCredentialIds
     */
    public function registrationOptions(string $accountId, string $challenge, array $excludedCredentialIds = []): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->config->rpName, $this->config->rpId),
            PublicKeyCredentialUserEntity::create($accountId, $accountId, $accountId),
            $challenge,
            pubKeyCredParams: array_map(
                static fn(int $algorithm): PublicKeyCredentialParameters => PublicKeyCredentialParameters::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $algorithm,
                ),
                $this->algorithms(),
            ),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: $this->config->userVerification,
                residentKey: $this->config->residentKey,
            ),
            attestation: $this->config->attestation,
            excludeCredentials: $this->descriptors($excludedCredentialIds),
            timeout: $this->timeout(),
        );
    }

    /**
     * @return list<int>
     */
    private function algorithms(): array
    {
        $mapped = [];

        foreach ($this->config->algorithms as $algorithm) {
            $mapped[] = match ($algorithm) {
                'ES256' => -7,
                'RS256' => -257,
                default => -7,
            };
        }

        return array_values(array_unique($mapped));
    }

    private function decodeCredentialId(string $credentialId): string
    {
        $decoded = Base64Url::decode($credentialId);

        return $decoded !== '' ? $decoded : $credentialId;
    }

    /**
     * @param list<string> $credentialIds
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(array $credentialIds): array
    {
        return array_map(
            fn(string $credentialId): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->decodeCredentialId($credentialId),
                $this->config->transports,
            ),
            array_values(array_filter($credentialIds, static fn(string $credentialId): bool => $credentialId !== '')),
        );
    }

    /**
     * @return int<1, max>
     */
    private function timeout(): int
    {
        return max(1, $this->config->timeout);
    }
}
