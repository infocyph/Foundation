<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationCodeStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class AuthorizationCodeManager
{
    public function __construct(
        private OAuthAuthorizationCodeStoreInterface $codes,
        private OAuthAuthorizationStoreInterface $authorizations,
        private AuthorizerInterface $authorizer,
        private ClockInterface $clock,
        private OpaqueToken $tokens,
        private int $ttlSeconds = 60,
        private ?OAuthAuditRecorder $audit = null,
    ) {
        if ($this->ttlSeconds < 1 || $this->ttlSeconds > 60) {
            throw new \InvalidArgumentException('OAuth authorization code TTL must be between 1 and 60 seconds.');
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
        $challenge = $this->pkceChallenge($codeVerifier);

        try {
            $codeHash = $this->tokens->hash($code);
        } catch (\Throwable) {
            throw OAuthProtocolException::invalidGrant();
        }

        $result = $this->codes->consume(
            codeHash: $codeHash,
            clientId: $clientId,
            redirectUriHash: hash('sha256', $redirectUri),
            pkceChallenge: $challenge,
            now: $this->clock->now(),
        );
        $this->auditConsumeResult($result, $clientId);
        if (!$result->consumed() || !$result->code instanceof OAuthAuthorizationCode) {
            throw OAuthProtocolException::invalidGrant();
        }

        return $result->code;
    }

    public function issue(AuthorizationRequest $request, PrincipalInterface $principal): OAuthAuthorizationCodeIssue
    {
        $accountId = $principal->accountId();
        if (!is_string($accountId) || $accountId === '') {
            throw new \LogicException('OAuth authorization code issuance requires an account principal.');
        }
        $this->assertPermissions($principal, $request);

        $now = $this->clock->now();
        $authorization = new OAuthAuthorization(
            id: bin2hex(random_bytes(16)),
            clientId: $request->client->clientId,
            accountId: $accountId,
            scopes: $request->scopes,
            audiences: $request->audiences,
            createdAt: $now,
        );
        $this->authorizations->save($authorization);

        $plainCode = $this->tokens->issue(64);
        $expiresAt = $now + $this->ttlSeconds;
        $this->codes->save(new OAuthAuthorizationCode(
            id: bin2hex(random_bytes(16)),
            codeHash: $this->tokens->hash($plainCode),
            clientId: $request->client->clientId,
            accountId: $accountId,
            authorizationId: $authorization->id,
            redirectUriHash: hash('sha256', $request->redirectUri),
            pkceChallenge: $request->codeChallenge,
            scopes: $request->scopes,
            audiences: $request->audiences,
            issuedAt: $now,
            expiresAt: $expiresAt,
        ));

        return new OAuthAuthorizationCodeIssue($plainCode, $authorization, $expiresAt);
    }

    private function assertPermissions(PrincipalInterface $principal, AuthorizationRequest $request): void
    {
        foreach ($request->requiredPermissions as $permission) {
            if (!$this->authorizer->can($principal, $permission)->allowed) {
                throw new \LogicException('OAuth scope permission policy denied the authorization request.');
            }
        }
    }

    private function auditConsumeResult(OAuthAuthorizationCodeConsumeResult $result, string $clientId): void
    {
        $code = $result->code;
        $metadata = [
            'client_id' => $clientId,
            'authorization_id' => $code?->authorizationId,
            'result' => $result->status->value,
        ];
        $accountId = $code?->accountId;

        switch ($result->status) {
            case OAuthAuthorizationCodeConsumeStatus::Consumed:
                $this->audit?->record(
                    AuthEventType::OAUTH_AUTHORIZATION_CODE_CONSUMED,
                    $accountId,
                    $metadata,
                );
                break;
            case OAuthAuthorizationCodeConsumeStatus::Expired:
                $this->audit?->record(
                    AuthEventType::OAUTH_AUTHORIZATION_CODE_EXPIRED,
                    $accountId,
                    $metadata,
                    AuthEventSeverity::WARNING,
                );
                break;
            case OAuthAuthorizationCodeConsumeStatus::Reused:
                $this->audit?->record(
                    AuthEventType::OAUTH_AUTHORIZATION_CODE_REPLAY,
                    $accountId,
                    $metadata,
                    AuthEventSeverity::WARNING,
                );
                break;
            case OAuthAuthorizationCodeConsumeStatus::Missing:
            case OAuthAuthorizationCodeConsumeStatus::Mismatched:
                break;
        }
    }

    private function pkceChallenge(#[\SensitiveParameter] string $verifier): string
    {
        $length = strlen($verifier);
        if ($length < 43 || $length > 128 || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $verifier) !== 1) {
            throw OAuthProtocolException::invalidGrant();
        }

        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
