<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\Support\Base64Url;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Webauthn\CredentialRecord;

final readonly class WebAuthnCredentialMapper
{
    public function __construct(
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock,
        private WebAuthnRuntime $runtime,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function fromCredentialRecord(string $accountId, CredentialRecord $record, array $metadata = []): PasskeyCredential
    {
        return new PasskeyCredential(
            id: $this->ids->credentialId(),
            accountId: $accountId,
            credentialId: Base64Url::encode($record->publicKeyCredentialId),
            publicKey: Base64Url::encode($record->credentialPublicKey),
            signCount: $record->counter,
            transports: $this->transports($record->transports),
            createdAt: $this->clock->now(),
            metadata: $this->metadata($metadata, $record),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function metadata(array $metadata, CredentialRecord $record): array
    {
        unset($metadata['credential'], $metadata['credential_json']);

        $webauthn = $metadata['webauthn'] ?? [];
        if (!is_array($webauthn)) {
            $webauthn = [];
        }

        $webauthn['credential_id'] = Base64Url::encode($record->publicKeyCredentialId);
        $webauthn['credential_record'] = $this->runtime->normalizeCredentialRecord($record);
        $webauthn['user_handle'] = Base64Url::encode($record->userHandle);

        $metadata['webauthn'] = $webauthn;

        return $metadata;
    }

    /**
     * @param array<int|string, string> $transports
     * @return list<string>
     */
    private function transports(array $transports): array
    {
        $normalized = [];

        foreach ($transports as $transport) {
            if ($transport === '') {
                continue;
            }

            $normalized[] = $transport;
        }

        return $normalized;
    }
}
