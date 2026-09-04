<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigExportValidator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

/** Immutable release/runtime settings derived from normalized Foundation config. */
final readonly class WebReleaseConfiguration
{
    private const array BUILT_IN_ALIASES = [
        'signed' => 'verify_signed_url',
        'throttle' => 'throttle',
    ];

    public function __construct(private WebGraphComposition $graph) {}

    public function assertArtifactSafeMiddleware(): void
    {
        $aliases = $this->config()->get('router.middleware.aliases', []);
        $pre = $this->config()->get('router.middleware.globals.pre', []);
        $post = $this->config()->get('router.middleware.globals.post', []);

        if (!$this->artifactSafeAliases($aliases)
            || !is_array($pre)
            || $pre !== []
            || !is_array($post)
            || $post !== []
        ) {
            throw new ConfigurationException(
                'Configured Webrick aliases/global middleware are not yet eligible for Foundation compiled releases; '
                . 'use Foundation built-in route aliases until their artifact-safe resolver bridge is enabled.',
            );
        }
    }

    public function configFingerprint(): string
    {
        $config = $this->graph->context->config;
        ConfigExportValidator::assertExportable($config);

        return hash('sha256', serialize($this->canonicalize($config)));
    }

    public function debug(): bool
    {
        return ValueNormalizer::bool($this->config()->get('app.debug'), false);
    }

    public function environment(): string
    {
        return $this->graph->context->environment ?? 'production';
    }

    public function maintenanceMiddlewareEnabled(): bool
    {
        return ValueNormalizer::bool(
            $this->config()->get('operations.maintenance.web.enabled', false),
            false,
        );
    }

    public function matcher(): MatcherInterface
    {
        return match (strtolower($this->string('router.matcher', 'fused'))) {
            'generated' => GeneratedMatcher::make(),
            'sharded' => ShardedMatcher::make(),
            default => FusedMatcher::make(),
        };
    }

    /** @return list<mixed> */
    public function preGlobal(): array
    {
        return $this->maintenanceMiddlewareEnabled()
            ? [new RuntimeMiddlewareDescriptor([RouteMiddlewareRuntimeResolver::class, 'maintenance'])]
            : [];
    }

    public function resolvePath(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Web release artifact path must not be empty.');
        }
        if (preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $this->graph->application->basePath($path);
    }

    /** @return array<string, mixed> */
    public function registrarOptions(): array
    {
        return [
            'autoSlashRedirect' => ValueNormalizer::bool($this->config()->get('router.auto_slash_redirect'), false),
            'exposeUrlServices' => ValueNormalizer::bool($this->config()->get('router.expose_url_services'), false),
            'signKey' => $this->signKey(),
            'signedDefaultTtl' => $this->signedDefaultTtl(),
            'signedUrlConfig' => $this->signedUrlOptions(),
            'urlBaseUri' => $this->urlBaseUri(),
        ];
    }

    public function signKey(): ?string
    {
        return ValueNormalizer::nullableString($this->config()->get('router.signed_urls.key'));
    }

    public function signedDefaultTtl(): ?int
    {
        $value = $this->config()->get('router.signed_urls.default_ttl');

        return is_int($value) ? $value : (is_string($value) && $value !== '' ? (int) $value : null);
    }

    public function signedUrlConfig(): ?SignedUrlConfig
    {
        $options = $this->signedUrlOptions();

        return $options !== null ? SignedUrlConfig::fromArray($options) : null;
    }

    public function urlBaseUri(): string
    {
        return $this->string('router.url_base_uri');
    }

    private function artifactSafeAliases(mixed $aliases): bool
    {
        if (!is_array($aliases) || $aliases === []) {
            return is_array($aliases);
        }

        $expected = self::BUILT_IN_ALIASES;
        ksort($aliases, SORT_STRING);
        ksort($expected, SORT_STRING);

        return $aliases === $expected;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
    }

    private function config(): ConfigRepository
    {
        return $this->graph->config;
    }

    /** @return array<string, mixed>|null */
    private function signedUrlOptions(): ?array
    {
        $configured = $this->config()->get('router.signed_urls.options');
        if (!is_array($configured)) {
            return null;
        }

        $normalized = [];
        foreach ($configured as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[match ($key) {
                'default_ttl' => 'defaultTtl',
                'expiry_param' => 'expiryParam',
                'generation_key' => 'generationKey',
                'ignored_query_params' => 'ignoredQueryParams',
                'payload_mode' => 'payloadMode',
                'signature_param' => 'signatureParam',
                'verification_keys' => 'verificationKeys',
                default => $key,
            }] = $value;
        }

        return $normalized !== [] ? $normalized : null;
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->config()->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
