<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

/** Synthetic credential-name combinations for the shared audit store boundary. */
final class AuthAuditSensitiveKeyMatrix
{
    /** @return array<string, string> */
    public static function identifiers(): array
    {
        return [
            'request_id' => 'safe-request',
            'TokenId' => 'safe-token-id',
            'TOKEN_ID' => 'safe-uppercase-token-id',
            'token_type' => 'safe-token-type',
            'credential_id' => 'safe-credential-id',
            'authorization_id' => 'safe-authorization-id',
            'device' => 'browser',
        ];
    }

    /** @return array<string, string> */
    public static function sensitive(): array
    {
        $keys = [];
        foreach ([
            'password', 'passwd', 'passphrase', 'secret', 'token', 'authorization',
            'credential', 'signature', 'otp', 'pin', 'seed', 'bearer',
            'jwt', 'assertion', 'cookie', 'recovery_codes', 'api_key',
            'private_key', 'authenticator_data', 'attestation_object',
            'code_verifier', 'mfa_otp', 'account_pin', 'mfa_code_verifier',
        ] as $family) {
            $variants = [
                $family,
                strtoupper($family),
                str_replace('_', '-', $family),
                str_replace('_', '', $family),
                'mFa' . ucfirst(str_replace('_', '', $family)),
                'account_' . $family,
            ];
            foreach ($variants as $key) {
                $keys[$key] = 'SENSITIVE-SENTINEL-' . count($keys);
            }
        }

        foreach (['mFaOtP', 'aCcOuNtPiN', 'MFA_OTP', 'ACCOUNT_PIN', 'codeVerifierValue', 'mfa_code_verifier', 'CLIENT_CODE_VERIFIER'] as $key) {
            $keys[$key] = 'SENSITIVE-SENTINEL-' . count($keys);
        }

        foreach (self::pinVariants() as $key) {
            $keys[$key] = 'SENSITIVE-SENTINEL-' . count($keys);
        }

        return $keys;
    }

    private static function alternatingCase(string $name): string
    {
        $mixed = '';
        foreach (str_split($name) as $index => $character) {
            $mixed .= $index % 2 === 0 ? strtolower($character) : strtoupper($character);
        }

        return $mixed;
    }

    /** @return list<string> */
    private static function pinVariants(): array
    {
        $variants = [];
        foreach (['', 'account_', 'mfa_', 'new_', 'current_'] as $prefix) {
            foreach (['input', 'data', 'entry', 'confirmation', 'response', 'challenge'] as $suffix) {
                $name = $prefix . 'pin_' . $suffix;
                $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
                $mixed = self::alternatingCase(str_replace('_', '', $name));

                foreach ([$name, strtoupper($name), str_replace('_', '-', $name), $camel, ucfirst($camel), $mixed] as $key) {
                    $variants[] = $key;
                }
            }
        }

        return $variants;
    }
}
