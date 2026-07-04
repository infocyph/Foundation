<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

final readonly class HmacTokenCodec
{
    public function __construct(
        private string $secret,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($payload);
        if ($decoded === null) {
            return null;
        }

        $claims = json_decode($decoded, true);
        if (!is_array($claims)) {
            return null;
        }

        $normalized = [];
        foreach ($claims as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims): string
    {
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));

        return $payload . '.' . $signature;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
