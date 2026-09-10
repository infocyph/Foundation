<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final readonly class EpicryptPurposeTokenFactory
{
    private const string CONTEXT = 'foundation.auth.simple-token.v1';

    private const string DOMAIN_PREFIX = 'foundation.auth.simple-token.';

    private const int KEY_BYTES = 64;

    private EpicryptClockAdapter $clock;

    private KeyDeriver $deriver;

    public function __construct(
        #[\SensitiveParameter]
        private string $masterKey,
        ClockInterface $clock,
    ) {
        $this->clock = new EpicryptClockAdapter($clock);
        $this->deriver = new KeyDeriver();
    }

    public function forPurpose(string $purpose, int $ttlSeconds): PurposeToken
    {
        $domain = self::domain($purpose);

        return new PurposeToken(
            keys: $this->deriver->derivePurposeKeyBinary(
                $this->masterKey,
                $domain,
                self::CONTEXT,
                self::KEY_BYTES,
            ),
            purpose: $domain,
            context: self::CONTEXT,
            ttlSeconds: $ttlSeconds,
            clock: $this->clock,
        );
    }

    private static function domain(string $purpose): string
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Foundation simple-token purpose is invalid.');
        }

        return self::DOMAIN_PREFIX . $purpose . '.v1';
    }
}
