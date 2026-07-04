<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Support\ValueNormalizer;
use JsonException;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

use const JSON_THROW_ON_ERROR;

final class WebAuthnRuntime
{
    private ?AuthenticatorAssertionResponseValidator $assertionValidator = null;

    private ?AuthenticatorAttestationResponseValidator $attestationValidator = null;

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly WebAuthnConfig $config,
    ) {}

    public function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        if ($this->assertionValidator instanceof AuthenticatorAssertionResponseValidator) {
            return $this->assertionValidator;
        }

        return $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyFactory()->requestCeremony(),
        );
    }

    public function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        if ($this->attestationValidator instanceof AuthenticatorAttestationResponseValidator) {
            return $this->attestationValidator;
        }

        return $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyFactory()->creationCeremony(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function creationOptionsToArray(PublicKeyCredentialCreationOptions $options): array
    {
        return $this->deserializeToArray($this->serializer()->serialize($options, 'json'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function denormalizeCredentialRecord(array $payload): CredentialRecord
    {
        return $this->serializer()->deserialize(
            $this->encodeJson($payload),
            CredentialRecord::class,
            'json',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function loadCredential(array $payload): PublicKeyCredential
    {
        return $this->serializer()->deserialize(
            $this->encodeJson($payload),
            PublicKeyCredential::class,
            'json',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeCredentialRecord(CredentialRecord $record): array
    {
        return $this->deserializeToArray($this->serializer()->serialize($record, 'json'));
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOptionsToArray(PublicKeyCredentialRequestOptions $options): array
    {
        return $this->deserializeToArray($this->serializer()->serialize($options, 'json'));
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        if ($this->config->origin !== null) {
            $factory->setAllowedOrigins([$this->config->origin]);
        }

        if ($this->config->rpId !== null) {
            $factory->setSecuredRelyingPartyId([$this->config->rpId]);
        }

        return $factory;
    }

    /**
     * @return array<string, mixed>
     */
    private function deserializeToArray(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WebAuthnException('Unable to decode WebAuthn JSON payload.', 0, $exception);
        }

        return ValueNormalizer::associativeArray($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WebAuthnException('Unable to encode WebAuthn payload.', 0, $exception);
        }
    }

    private function serializer(): SerializerInterface
    {
        if ($this->serializer instanceof SerializerInterface) {
            return $this->serializer;
        }

        $factory = new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create([
                NoneAttestationStatementSupport::create(),
            ]),
        );

        return $this->serializer = $factory->create();
    }
}
