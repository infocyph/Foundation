<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;

interface OAuthAuthorizationStoreInterface
{
    public function find(string $authorizationId): ?OAuthAuthorization;

    /** @return list<OAuthAuthorization> */
    public function recent(int $limit = 100, ?string $clientId = null): array;

    public function revoke(string $authorizationId, int $revokedAt): bool;

    public function save(OAuthAuthorization $authorization): void;
}
