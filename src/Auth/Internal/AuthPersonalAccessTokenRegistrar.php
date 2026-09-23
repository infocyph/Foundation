<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenManager;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenPolicy;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenStoreInterface;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenUsageStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEpicryptPersonalAccessTokenStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptAsymmetricSigningKeyResolver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class AuthPersonalAccessTokenRegistrar extends AbstractAuthRegistrar
{
    private const string CLOCK = 'foundation.auth.personal-access-token.clock';

    private const string KEYS = 'foundation.auth.personal-access-token.keys';

    public function register(): void
    {
        if (!$this->boolConfig('auth.personal_access_tokens.enabled', false)) {
            return;
        }

        $this->requirePackage(Connection::class, 'infocyph/dblayer', 'database');
        $this->requirePackage(PersonalAccessTokenManager::class, 'infocyph/epicrypt', 'crypto');

        $connection = $this->nullableString($this->app->config()->get('database.default'));
        $store = DBLayerEpicryptPersonalAccessTokenStore::class;

        $this->recipe($store, $store, [
            $this->ref(DBLayerFactory::class),
            $this->ref(AuthTables::class),
            $connection,
        ]);
        $this->alias(PersonalAccessTokenStoreInterface::class, $store);
        $this->alias(PersonalAccessTokenUsageStoreInterface::class, $store);

        $this->recipe(EpicryptAsymmetricSigningKeyResolver::class, EpicryptAsymmetricSigningKeyResolver::class, [
            $this->ref(ConfigRepository::class),
        ]);
        $this->staticRecipe(self::KEYS, AuthPersonalAccessTokenGraphFactory::class, 'keys', [
            $this->ref(EpicryptAsymmetricSigningKeyResolver::class),
            $this->stringConfig('auth.personal_access_tokens.issuer', ''),
        ]);
        $this->staticRecipe(PersonalAccessTokenPolicy::class, AuthPersonalAccessTokenGraphFactory::class, 'policy', [
            $this->stringConfig('auth.personal_access_tokens.audience', ''),
            $this->intConfig('auth.personal_access_tokens.default_lifetime_seconds', 2_592_000),
            $this->intConfig('auth.personal_access_tokens.maximum_lifetime_seconds', 31_536_000),
            $this->stringConfig('auth.personal_access_tokens.wildcard_policy', 'disabled'),
            $this->intConfig('auth.personal_access_tokens.last_used_write_interval_seconds', 300),
        ]);
        $this->recipe(self::CLOCK, EpicryptClockAdapter::class, [
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(PersonalAccessTokenManager::class, PersonalAccessTokenManager::class, [
            $this->ref(self::KEYS),
            $this->ref(PersonalAccessTokenStoreInterface::class),
            $this->ref(PersonalAccessTokenPolicy::class),
            $this->ref(PersonalAccessTokenUsageStoreInterface::class),
            $this->ref(self::CLOCK),
        ]);
    }
}
