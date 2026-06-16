<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class TokenAuthManager
{
    public function __construct(
        private AccessTokenServiceInterface $accessTokens,
        private RefreshTokenServiceInterface $refreshTokenService,
        private RefreshTokenStoreInterface $refreshTokens,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private int $refreshTtlSeconds = 1209600,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function issueAccessToken(AccessTokenClaims $claims, array $context = []): TokenAuthResult
    {
        $token = $this->accessTokens->issue($claims);
        $this->record(AuthEventType::ACCESS_TOKEN_ISSUED, $claims->subjectId, $claims->metadata + $context);

        return new TokenAuthResult(TokenType::ACCESS, token: $token, code: 'access_token_issued', context: $context);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function issueRefreshToken(string $accountId, ?string $clientId = null, ?string $deviceId = null, array $metadata = []): TokenAuthResult
    {
        $now = $this->clock->now();
        $claims = new RefreshTokenClaims(
            tokenId: $this->ids->challengeId(),
            accountId: $accountId,
            familyId: $this->ids->correlationId(),
            clientId: $clientId,
            deviceId: $deviceId,
            issuedAt: $now,
            expiresAt: $now + $this->refreshTtlSeconds,
            metadata: $metadata,
        );

        $issued = $this->refreshTokenService->issue($claims);
        $record = new RefreshTokenRecord(
            id: $issued->tokenId,
            accountId: $accountId,
            tokenHash: $issued->tokenHash,
            familyId: $issued->familyId,
            clientId: $clientId,
            deviceId: $deviceId,
            issuedAt: $claims->issuedAt,
            expiresAt: $issued->expiresAt,
            metadata: $metadata,
        );

        $this->refreshTokens->save($record);
        $this->record(AuthEventType::REFRESH_TOKEN_ISSUED, $accountId, ['token_id' => $record->id, 'family_id' => $record->familyId] + $metadata);

        return new TokenAuthResult(TokenType::REFRESH, token: $issued->value, refreshToken: $record, code: 'refresh_token_issued', context: $metadata);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function revokeRefreshFamily(string $familyId, array $context = []): TokenRevocationResult
    {
        if ($this->refreshTokens->wasFamilyRevoked($familyId)) {
            return new TokenRevocationResult(
                TokenRevocationStatus::ALREADY_REVOKED,
                $familyId,
                'refresh_token_family_already_revoked',
                $context,
            );
        }

        $this->refreshTokens->revokeFamily($familyId);
        $this->record(AuthEventType::REFRESH_TOKEN_REVOKED, ContextValue::stringOrNull($context, 'account_id'), ['family_id' => $familyId] + $context, AuthEventSeverity::NOTICE);

        return new TokenRevocationResult(TokenRevocationStatus::REVOKED, $familyId, 'refresh_token_family_revoked', $context);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function rotateRefreshToken(RefreshTokenRecord $current, array $metadata = []): RefreshTokenRotationResult
    {
        if ($this->refreshTokens->wasFamilyRevoked($current->familyId)) {
            $this->auditReuse($current, ['reason' => 'family_revoked'] + $metadata);

            return new RefreshTokenRotationResult(false, record: $current, code: 'refresh_token_family_revoked', context: $metadata);
        }

        if ($current->isExpiredAt($this->clock->now()) || $current->isRevoked()) {
            $this->refreshTokens->revokeFamily($current->familyId);
            $this->auditReuse($current, ['reason' => 'stale_token_reuse'] + $metadata);

            return new RefreshTokenRotationResult(false, record: $current, code: 'refresh_token_reuse_detected', context: $metadata);
        }

        $now = $this->clock->now();
        $claims = new RefreshTokenClaims(
            tokenId: $this->ids->challengeId(),
            accountId: $current->accountId,
            familyId: $current->familyId,
            clientId: $current->clientId,
            deviceId: $current->deviceId,
            issuedAt: $now,
            expiresAt: $now + $this->refreshTtlSeconds,
            metadata: $metadata ?: $current->metadata,
        );

        $issued = $this->refreshTokenService->issue($claims);
        $replacement = new RefreshTokenRecord(
            id: $issued->tokenId,
            accountId: $current->accountId,
            tokenHash: $issued->tokenHash,
            familyId: $current->familyId,
            clientId: $current->clientId,
            deviceId: $current->deviceId,
            issuedAt: $claims->issuedAt,
            expiresAt: $issued->expiresAt,
            metadata: $claims->metadata,
        );

        $this->refreshTokens->rotate($current->id, $replacement);
        $this->record(AuthEventType::REFRESH_TOKEN_ROTATED, $current->accountId, ['token_id' => $replacement->id, 'family_id' => $replacement->familyId] + $metadata);

        return new RefreshTokenRotationResult(true, token: $issued->value, record: $replacement, code: 'refresh_token_rotated', context: $metadata);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verifyAccessToken(string $token, array $context = []): TokenAuthResult
    {
        $verification = $this->accessTokens->verify($token);

        return new TokenAuthResult(
            TokenType::ACCESS,
            token: $token,
            verification: $verification,
            code: $verification->verified ? 'access_token_verified' : ($verification->failureReason ?? 'access_token_invalid'),
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function verifyRefreshToken(string $token, array $metadata = []): TokenAuthResult
    {
        $verification = $this->refreshTokenService->verify($token);

        return new TokenAuthResult(
            TokenType::REFRESH,
            token: $token,
            verification: $verification,
            code: $verification->verified ? 'refresh_token_verified' : ($verification->failureReason ?? 'refresh_token_invalid'),
            context: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function auditReuse(RefreshTokenRecord $record, array $metadata = []): void
    {
        $this->record(
            AuthEventType::REFRESH_TOKEN_REUSE_DETECTED,
            $record->accountId,
            ['family_id' => $record->familyId, 'token_id' => $record->id] + $metadata,
            AuthEventSeverity::WARNING,
            $record->deviceId,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(
        AuthEventType $type,
        ?string $accountId,
        array $metadata = [],
        AuthEventSeverity $severity = AuthEventSeverity::INFO,
        ?string $deviceId = null,
    ): void {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            $type,
            $accountId,
            metadata: $metadata,
            severity: $severity,
            deviceId: $deviceId ?? ContextValue::stringOrNull($metadata, 'device_id'),
            sessionId: ContextValue::stringOrNull($metadata, 'session_id'),
        );
    }
}
