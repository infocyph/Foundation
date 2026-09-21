<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationApproval;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeConsumer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeIssuer;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeConsumeResult as EpicryptConsumeResult;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationCodeConsumeStatus as EpicryptConsumeStatus;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRecord;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRequest as EpicryptAuthorizationRequest;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class AuthorizationCodeManager
{
    public function __construct(
        private OAuthAuthorizationCodeIssuer $issuer,
        private OAuthAuthorizationCodeConsumer $consumer,
        private AuthorizerInterface $authorizer,
        private ClockInterface $clock,
        private int $ttlSeconds = 60,
        private ?OAuthAuditRecorder $audit = null,
    ) {
        if ($this->ttlSeconds < 1 || $this->ttlSeconds > 600) {
            throw new \InvalidArgumentException('OAuth authorization code TTL must be between 1 and 600 seconds.');
        }
    }

    public function consume(
        #[\SensitiveParameter]
        string $code,
        string $clientId,
        string $redirectUri,
        #[\SensitiveParameter]
        string $codeVerifier,
    ): OAuthAuthorizationCode {
        $result = $this->consumer->consume($code, $clientId, $redirectUri, $codeVerifier);
        $this->auditConsumeResult($result, $clientId);
        if (!$result->consumed || $result->code === null) {
            throw OAuthProtocolException::invalidGrant();
        }

        $protocol = $result->code;

        return new OAuthAuthorizationCode(
            id: $protocol->codeId,
            codeHash: hash('sha256', $code),
            clientId: $protocol->clientId,
            accountId: $protocol->subject,
            authorizationId: $protocol->authorizationId,
            redirectUriHash: $protocol->redirectUriHash,
            pkceChallenge: $protocol->pkceChallenge,
            scopes: $protocol->scopes,
            audiences: $protocol->audiences,
            issuedAt: $protocol->issuedAt,
            expiresAt: $protocol->expiresAt,
            consumedAt: $this->clock->now(),
        );
    }

    public function issue(AuthorizationRequest $request, PrincipalInterface $principal): OAuthAuthorizationCodeIssue
    {
        $accountId = $principal->accountId();
        if (!is_string($accountId) || $accountId === '') {
            throw new \LogicException('OAuth authorization code issuance requires an account principal.');
        }
        $this->assertPermissions($principal, $request);

        $metadata = $principal->metadata();
        $authenticationTime = $metadata['auth_time'] ?? $this->clock->now();
        if (!is_int($authenticationTime) || $authenticationTime < 1) {
            $authenticationTime = $this->clock->now();
        }
        $authenticationContext = is_string($metadata['acr'] ?? null) ? $metadata['acr'] : null;
        $authenticationMethods = is_array($metadata['amr'] ?? null)
            ? array_values(array_filter($metadata['amr'], is_string(...)))
            : [];

        $issued = $this->issuer->issue(
            new EpicryptAuthorizationRequest(
                clientId: $request->client->clientId,
                redirectUri: $request->redirectUri,
                scopes: $request->scopes,
                audiences: $request->audiences,
                codeChallenge: $request->codeChallenge,
                state: $request->state,
            ),
            new OAuthAuthorizationApproval(
                subject: $accountId,
                scopes: $request->scopes,
                authenticationTime: $authenticationTime,
                authenticationContext: $authenticationContext,
                authenticationMethods: $authenticationMethods,
            ),
            $this->ttlSeconds,
            $request->openIdNonce,
        );

        return new OAuthAuthorizationCodeIssue(
            code: $issued->token,
            authorization: $this->authorization($issued->authorization),
            expiresAt: $issued->code->expiresAt,
        );
    }

    private function assertPermissions(PrincipalInterface $principal, AuthorizationRequest $request): void
    {
        foreach ($request->requiredPermissions as $permission) {
            if (!$this->authorizer->can($principal, $permission)->allowed) {
                throw new \LogicException('OAuth scope permission policy denied the authorization request.');
            }
        }
    }

    private function auditConsumeResult(EpicryptConsumeResult $result, string $clientId): void
    {
        $code = $result->code;
        $metadata = [
            'client_id' => $clientId,
            'authorization_id' => $code?->authorizationId,
            'result' => $result->status->value,
        ];
        $accountId = $code?->subject;

        match ($result->status) {
            EpicryptConsumeStatus::CONSUMED => $this->audit?->record(
                AuthEventType::OAUTH_AUTHORIZATION_CODE_CONSUMED,
                $accountId,
                $metadata,
            ),
            EpicryptConsumeStatus::EXPIRED => $this->audit?->record(
                AuthEventType::OAUTH_AUTHORIZATION_CODE_EXPIRED,
                $accountId,
                $metadata,
                AuthEventSeverity::WARNING,
            ),
            EpicryptConsumeStatus::REPLAYED => $this->audit?->record(
                AuthEventType::OAUTH_AUTHORIZATION_CODE_REPLAY,
                $accountId,
                $metadata,
                AuthEventSeverity::WARNING,
            ),
            default => null,
        };
    }

    private function authorization(OAuthAuthorizationRecord $record): OAuthAuthorization
    {
        return new OAuthAuthorization(
            id: $record->authorizationId,
            clientId: $record->clientId,
            accountId: $record->subject,
            scopes: $record->scopes,
            audiences: $record->audiences,
            createdAt: $record->authorizedAt,
            expiresAt: $record->expiresAt,
            revokedAt: $record->revokedAt,
        );
    }
}
