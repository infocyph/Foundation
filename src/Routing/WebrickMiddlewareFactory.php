<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\InputSanitizerMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\NormalizeMethodMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\NullLogger;

/**
 * @phpstan-type MiddlewareDefinition array<string, mixed>
 */
final readonly class WebrickMiddlewareFactory
{
    public function __construct(
        private Application $app,
        private ConfigRepository $config,
    ) {}

    /**
     * @return list<object|string>
     */
    public function postGlobal(): array
    {
        return $this->resolveGlobalList('post');
    }

    /**
     * @return list<object|string>
     */
    public function preGlobal(): array
    {
        return $this->resolveGlobalList('pre');
    }

    public function registerAliases(): void
    {
        foreach ($this->configuredAliases() as $alias => $definition) {
            if (!$this->enabled($definition) || !$this->buildable($definition)) {
                continue;
            }

            MiddlewareAliases::register(
                $alias,
                fn(string ...$parameters): object|string => $this->aliasMiddleware($definition, ...$parameters),
            );
        }
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function aliasMiddleware(array $definition, string ...$parameters): object|string
    {
        $driver = $this->driverName($definition['driver'] ?? null);

        if ($driver === 'throttle') {
            if (isset($parameters[0]) && is_numeric($parameters[0])) {
                $definition['max'] = (int) $parameters[0];
            }

            if (isset($parameters[1]) && is_numeric($parameters[1])) {
                $definition['window'] = (int) $parameters[1];
            }
        }

        $resolved = $this->middleware($definition);
        if ($resolved === null) {
            throw new \RuntimeException(sprintf(
                'Unable to build Webrick middleware alias for driver "%s".',
                $driver ?? 'unknown',
            ));
        }

        return $resolved;
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function buildable(array $definition): bool
    {
        $driver = $this->driverName($definition['driver'] ?? null);

        return match ($driver) {
            'cookie_encryption' => $this->cookieEncryption($definition) !== null,
            'verify_signed_url' => $this->verifySignedUrl($definition) !== null,
            default => true,
        };
    }

    private function cacheStore(mixed $name): ?CacheInterface
    {
        $store = ValueNormalizer::nullableString($name);

        try {
            return $this->app->cache()->store($store);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredAliases(): array
    {
        $configured = $this->middlewareSection('aliases');

        $aliases = [];

        foreach ($configured as $alias => $definition) {
            if ($alias === '') {
                continue;
            }

            $normalized = $this->normalizeDefinition($definition);
            if ($normalized === null) {
                continue;
            }

            $aliases[$alias] = $normalized;
        }

        return $aliases;
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function cookieEncryption(array $definition): ?CookieEncryptionMiddleware
    {
        $keys = ValueNormalizer::stringList($definition['keys'] ?? []);
        $key = ValueNormalizer::nullableString($definition['key'] ?? null);

        if ($keys === [] && $key !== null) {
            $keys = [$key];
        }

        if ($keys === []) {
            return null;
        }

        return new CookieEncryptionMiddleware(
            keyOrKeys: $keys,
            cookiePrefix: ValueNormalizer::string($definition['cookie_prefix'] ?? null, 'enc_'),
            maxBytes: ValueNormalizer::int($definition['max_bytes'] ?? null, 3800),
            store: $this->cacheStore($definition['store'] ?? null),
            storeTtl: ValueNormalizer::int($definition['store_ttl'] ?? null, 86400),
            dropOnDecryptFailure: ValueNormalizer::bool($definition['drop_on_decrypt_failure'] ?? null, true),
            forceSecure: ValueNormalizer::bool($definition['force_secure'] ?? null, true),
            forceHttpOnly: ValueNormalizer::bool($definition['force_http_only'] ?? null, true),
            defaultSameSite: array_key_exists('default_same_site', $definition)
                ? ValueNormalizer::nullableString($definition['default_same_site'])
                : 'Lax',
        );
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function cors(array $definition): CorsAndPoliciesMiddleware
    {
        return new CorsAndPoliciesMiddleware(
            origins: ValueNormalizer::stringList($definition['origins'] ?? ['*']),
            methods: ValueNormalizer::string($definition['methods'] ?? null, 'GET, POST, PUT, PATCH, DELETE, OPTIONS'),
            allowHeaders: $this->stringListOrCsv($definition['allow_headers'] ?? ['Content-Type', 'Authorization']),
            exposeHeaders: $this->stringListOrCsv($definition['expose_headers'] ?? [
                'Content-Length',
                'Content-Type',
                'ETag',
                'Server-Timing',
                'Location',
                'X-RateLimit-Limit',
                'X-RateLimit-Remaining',
                'X-RateLimit-Reset',
            ]),
            maxAgeSeconds: ValueNormalizer::int($definition['max_age_seconds'] ?? null, 3600),
            allowCredentials: ValueNormalizer::bool($definition['allow_credentials'] ?? null, true),
            allowPrivateNetwork: ValueNormalizer::bool($definition['allow_private_network'] ?? null, false),
            hsts: ValueNormalizer::bool($definition['hsts'] ?? null, true),
            hstsIncludeSubdomains: ValueNormalizer::bool($definition['hsts_include_subdomains'] ?? null, true),
            csp: array_key_exists('csp', $definition)
                ? ValueNormalizer::nullableString($definition['csp'])
                : "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",
            acceptCh: ValueNormalizer::stringList($definition['accept_ch'] ?? []),
            timingAllowOrigins: ValueNormalizer::stringList($definition['timing_allow_origins'] ?? []),
        );
    }

    private function driverName(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function enabled(array $definition): bool
    {
        return ValueNormalizer::bool($definition['enabled'] ?? true, true);
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function gatewayHardening(array $definition): GatewayHardeningMiddleware
    {
        return new GatewayHardeningMiddleware(
            trustedProxyCidrs: ValueNormalizer::stringList($definition['trusted_proxy_cidrs'] ?? []),
            denyIpCidrs: ValueNormalizer::stringList($definition['deny_ip_cidrs'] ?? []),
            trustedHosts: ValueNormalizer::stringList($definition['trusted_hosts'] ?? []),
            forwardedHeaderMask: is_int($definition['forwarded_header_mask'] ?? null)
                ? $definition['forwarded_header_mask']
                : (is_numeric($definition['forwarded_header_mask'] ?? null) ? (int) $definition['forwarded_header_mask'] : null),
            enforceHttps: ValueNormalizer::bool($definition['enforce_https'] ?? null, false),
            httpsPort: ValueNormalizer::int($definition['https_port'] ?? null, 443),
            stripHopByHop: ValueNormalizer::bool($definition['strip_hop_by_hop'] ?? null, true),
            redirectAllowedHosts: ValueNormalizer::stringList($definition['redirect_allowed_hosts'] ?? []),
        );
    }

    private function globalEntry(mixed $entry): object|string|null
    {
        if (is_object($entry)) {
            return $entry;
        }

        if (is_string($entry)) {
            $definition = $this->normalizeDefinition($entry);

            return $definition !== null
                ? $this->middleware($definition)
                : null;
        }

        if (!is_array($entry)) {
            return null;
        }

        $definition = $this->normalizeDefinition($entry);

        return $definition !== null
            ? $this->middleware($definition)
            : null;
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function inputSanitizer(array $definition): InputSanitizerMiddleware
    {
        return new InputSanitizerMiddleware(
            touchFormBodies: ValueNormalizer::bool($definition['touch_form_bodies'] ?? null, true),
            touchJsonBodies: ValueNormalizer::bool($definition['touch_json_bodies'] ?? null, false),
            touchUploadedNames: ValueNormalizer::bool($definition['touch_uploaded_names'] ?? null, false),
        );
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function maintenanceMode(array $definition): MaintenanceModeMiddleware
    {
        return new MaintenanceModeMiddleware(
            file: $this->path(ValueNormalizer::string($definition['file'] ?? null, 'storage/framework/down')),
            retryAfter: ValueNormalizer::int($definition['retry_after'] ?? null, 3600),
            contentType: ValueNormalizer::string($definition['content_type'] ?? null, 'text/plain'),
        );
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function middleware(array $definition): object|string|null
    {
        if (!$this->enabled($definition)) {
            return null;
        }

        $driver = $this->driverName($definition['driver'] ?? null);
        if ($driver === null) {
            return null;
        }

        return match ($driver) {
            'cache_validators' => new CacheValidatorsMiddleware(
                autoEtagWhenMissing: ValueNormalizer::bool($definition['auto_etag_when_missing'] ?? null, true),
                includeQueryInEtag: ValueNormalizer::bool($definition['include_query_in_etag'] ?? null, true),
                autoEtagMinSize: ValueNormalizer::int($definition['auto_etag_min_size'] ?? null, 2048),
            ),
            'compression' => new CompressionMiddleware(
                minBytes: ValueNormalizer::int($definition['min_bytes'] ?? null, 1400),
                prefOrder: ValueNormalizer::stringList($definition['pref_order'] ?? ['zstd', 'br', 'gzip']),
                etagMode: ValueNormalizer::string($definition['etag_mode'] ?? null, CompressionMiddleware::ETAG_WEAK_ON_ENCODE),
                gzipLevel: ValueNormalizer::int($definition['gzip_level'] ?? null, 6),
                brotliQuality: ValueNormalizer::int($definition['brotli_quality'] ?? null, 4),
                zstdLevel: ValueNormalizer::int($definition['zstd_level'] ?? null, 3),
                etagDeriveSalt: ValueNormalizer::string($definition['etag_derive_salt'] ?? null, 'enc-v1'),
                maxBufferBytes: ValueNormalizer::int($definition['max_buffer_bytes'] ?? null, 8_388_608),
                excludeTypes: ValueNormalizer::stringList($definition['exclude_types'] ?? []),
                onlyTypes: ValueNormalizer::stringList($definition['only_types'] ?? []),
                forceAddVary: ValueNormalizer::bool($definition['force_add_vary'] ?? null, true),
            ),
            'cookie_encryption' => $this->cookieEncryption($definition),
            'cors' => $this->cors($definition),
            'gateway_hardening' => $this->gatewayHardening($definition),
            'input_sanitizer' => $this->inputSanitizer($definition),
            'maintenance_mode' => $this->maintenanceMode($definition),
            'negotiation' => new NegotiationMiddleware(
                produces: ValueNormalizer::stringList($definition['produces'] ?? []),
                charsets: ValueNormalizer::stringList($definition['charsets'] ?? ['utf-8']),
                locales: ValueNormalizer::stringList($definition['locales'] ?? ['en']),
                localeFallback: ValueNormalizer::string($definition['locale_fallback'] ?? null, 'en'),
            ),
            'normalize_method' => new NormalizeMethodMiddleware(),
            'request_limits' => new RequestLimitsMiddleware(
                maxHeaderBytes: ValueNormalizer::int($definition['max_header_bytes'] ?? null, 8192),
                maxHeaderCount: ValueNormalizer::int($definition['max_header_count'] ?? null, 100),
                maxBodyBytes: array_key_exists('max_body_bytes', $definition) && is_numeric($definition['max_body_bytes'])
                    ? (int) $definition['max_body_bytes']
                    : null,
                bodyLimitVerbs: ValueNormalizer::stringList($definition['body_limit_verbs'] ?? []),
                violateOnUnknownBody: ValueNormalizer::bool($definition['violate_on_unknown_body'] ?? null, true),
            ),
            'response_cache' => new ResponseCacheMiddleware(
                store: $this->cacheStore($definition['store'] ?? null),
                ttlSeconds: ValueNormalizer::int($definition['ttl_seconds'] ?? null, 10),
                includeQuery: ValueNormalizer::bool($definition['include_query'] ?? null, true),
                maxBodyBytes: ValueNormalizer::int($definition['max_body_bytes'] ?? null, 1_048_576),
                defaultVary: ValueNormalizer::stringList($definition['default_vary'] ?? ['Accept', 'Accept-Language', 'Accept-Encoding']),
                skipWhenPersonalized: ValueNormalizer::bool($definition['skip_when_personalized'] ?? null, true),
                respectResponseCacheControl: ValueNormalizer::bool($definition['respect_response_cache_control'] ?? null, true),
                avoidSetCookie: ValueNormalizer::bool($definition['avoid_set_cookie'] ?? null, true),
            ),
            'response_linter' => new ResponseLinterMiddleware(
                checks: is_int($definition['checks'] ?? null) || is_bool($definition['checks'] ?? null)
                    ? $definition['checks']
                    : false,
            ),
            'telemetry' => new TelemetryMiddleware(
                log: new NullLogger(),
                addXResponseTime: ValueNormalizer::bool($definition['add_x_response_time'] ?? null, true),
                addServerTiming: ValueNormalizer::bool($definition['add_server_timing'] ?? null, true),
                emitRequestId: ValueNormalizer::bool($definition['emit_request_id'] ?? null, true),
                requestIdHeader: ValueNormalizer::string($definition['request_id_header'] ?? null, 'X-Request-Id'),
                respectExistingRequestId: ValueNormalizer::bool($definition['respect_existing_request_id'] ?? null, true),
                nelGroup: ValueNormalizer::nullableString($definition['nel_group'] ?? null),
                nelEndpoint: ValueNormalizer::nullableString($definition['nel_endpoint'] ?? null),
                nelTtlSeconds: ValueNormalizer::int($definition['nel_ttl_seconds'] ?? null, 86400),
                nelIncludeSubdomains: ValueNormalizer::bool($definition['nel_include_subdomains'] ?? null, true),
                nelCollectSuccesses: ValueNormalizer::bool($definition['nel_collect_successes'] ?? null, false),
                emitTraceIdHeader: ValueNormalizer::bool($definition['emit_trace_id_header'] ?? null, true),
                traceIdHeader: ValueNormalizer::string($definition['trace_id_header'] ?? null, 'Trace-Id'),
                respectIncomingTraceparent: ValueNormalizer::bool($definition['respect_incoming_traceparent'] ?? null, true),
                emitTraceparentHeader: ValueNormalizer::bool($definition['emit_traceparent_header'] ?? null, false),
                enableOtelIntegration: ValueNormalizer::bool($definition['enable_otel_integration'] ?? null, false),
                otelServiceName: ValueNormalizer::string($definition['otel_service_name'] ?? null, 'foundation-app'),
                otelServiceVersion: ValueNormalizer::string($definition['otel_service_version'] ?? null, '1.0.0'),
            ),
            'throttle' => new ThrottleMiddleware(
                max: ValueNormalizer::int($definition['max'] ?? null, 60),
                window: ValueNormalizer::int($definition['window'] ?? null, 60),
                pool: $this->cacheStore($definition['store'] ?? null),
                retryAsDate: ValueNormalizer::bool($definition['retry_as_date'] ?? null, false),
                emitStandardRateLimit: ValueNormalizer::bool($definition['emit_standard_rate_limit'] ?? null, true),
                scope: ValueNormalizer::string($definition['scope'] ?? null, 'global'),
                costAttribute: ValueNormalizer::string($definition['cost_attribute'] ?? null, 'rate_cost.thm'),
            ),
            'vary' => new VaryAccumulatorMiddleware(),
            'verify_signed_url' => $this->verifySignedUrl($definition),
            default => class_exists($driver) ? $this->resolveClass($driver) : null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function middlewarePreset(string $driver): array
    {
        return ValueNormalizer::associativeArray($this->config->get('router.middleware.definitions.' . $driver, []));
    }

    /**
     * @return array<string, mixed>
     */
    private function middlewareSection(string $section): array
    {
        return ValueNormalizer::associativeArray($this->config->get('router.middleware.' . $section, []));
    }

    /**
     * @return MiddlewareDefinition|null
     */
    private function normalizeDefinition(mixed $definition): ?array
    {
        if (is_string($definition) && $definition !== '') {
            return ['driver' => $definition];
        }

        if (!is_array($definition)) {
            return null;
        }

        $normalized = ValueNormalizer::associativeArray($definition);
        $driver = $this->driverName($normalized['driver'] ?? null);

        if ($driver === null && isset($normalized['class']) && is_string($normalized['class'])) {
            $driver = $normalized['class'];
        }

        if ($driver === null) {
            return null;
        }

        $presetConfig = $this->middlewarePreset($driver);

        return ['driver' => $driver] + $presetConfig + $normalized;
    }

    private function path(string $path): string
    {
        if ($path === '' || preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return rtrim($this->app->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function resolveClass(string $class): object|string
    {
        try {
            $service = $this->app->make($class);

            return is_object($service) ? $service : $class;
        } catch (\Throwable) {
            return $class;
        }
    }

    /**
     * @return list<object|string>
     */
    private function resolveGlobalList(string $phase): array
    {
        $configured = $this->config->get('router.middleware.globals.' . $phase, []);
        if (!is_array($configured)) {
            return [];
        }

        $middleware = [];

        foreach ($configured as $entry) {
            $resolved = $this->globalEntry($entry);
            if ($resolved === null) {
                continue;
            }

            $middleware[] = $resolved;
        }

        return $middleware;
    }

    /**
     */
    private function signedUrlConfig(mixed $optionConfig): SignedUrlConfig|string|null
    {
        $options = ValueNormalizer::associativeArray($optionConfig);

        if ($options === []) {
            return ValueNormalizer::nullableString($this->config->get('router.signed_urls.key'));
        }

        $generationKey = ValueNormalizer::nullableString($options['generationKey'] ?? $options['generation_key'] ?? null);
        $verificationKeys = ValueNormalizer::stringList($options['verificationKeys'] ?? $options['verification_keys'] ?? []);

        if ($verificationKeys === [] && $generationKey !== null) {
            $verificationKeys = [$generationKey];
        }

        if ($generationKey === null && $verificationKeys === []) {
            $legacyKey = ValueNormalizer::nullableString($this->config->get('router.signed_urls.key'));
            if ($legacyKey !== null) {
                $verificationKeys = [$legacyKey];
            }
        }

        if ($generationKey === null && $verificationKeys === []) {
            return null;
        }

        return SignedUrlConfig::fromArray([
            'generationKey' => $generationKey,
            'verificationKeys' => $verificationKeys,
            'defaultTtl' => $options['defaultTtl'] ?? $options['default_ttl'] ?? null,
            'signatureParam' => $options['signatureParam'] ?? $options['signature_param'] ?? SignedUrlConfig::DEFAULT_SIGNATURE_PARAM,
            'expiryParam' => $options['expiryParam'] ?? $options['expiry_param'] ?? SignedUrlConfig::DEFAULT_EXPIRY_PARAM,
            'algorithm' => $options['algorithm'] ?? SignedUrlConfig::DEFAULT_ALGORITHM,
            'payloadMode' => $options['payloadMode'] ?? $options['payload_mode'] ?? SignedUrlConfig::MODE_RELATIVE,
            'ignoredQueryParams' => $options['ignoredQueryParams'] ?? $options['ignored_query_params'] ?? [],
            'leeway' => $options['leeway'] ?? 0,
        ]);
    }

    /**
     * @return list<string>|string
     */
    private function stringListOrCsv(mixed $value): array|string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return ValueNormalizer::stringList($value);
    }

    /**
     * @param MiddlewareDefinition $definition
     */
    private function verifySignedUrl(array $definition): ?VerifySignedUrlMiddleware
    {
        $baseConfig = $this->signedUrlConfig($this->config->get('router.signed_urls.options', []));
        $definitionConfig = ValueNormalizer::associativeArray($definition['config'] ?? []);

        if ($definitionConfig !== []) {
            $baseConfig = $this->signedUrlConfig($definitionConfig);
        }

        if ($baseConfig === null) {
            return null;
        }

        return new VerifySignedUrlMiddleware(
            config: $baseConfig,
            leeway: is_int($definition['leeway'] ?? null) || is_string($definition['leeway'] ?? null)
                ? $definition['leeway']
                : null,
            ignoredQueryParams: ValueNormalizer::stringList($definition['ignored_query_params'] ?? []),
            payloadMode: ValueNormalizer::nullableString($definition['payload_mode'] ?? null),
        );
    }
}
