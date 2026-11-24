<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientDestinationPolicy;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\HttpOutboundDispatcher;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\OutboundAddressPolicy;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use JsonException;
use Throwable;

/**
 * Optional HTTPS challenge provider using a Turnstile-compatible response
 * contract (success, hostname, action, cdata). Provider configuration is
 * trusted deployment input; no client-supplied destination or TLS overrides.
 *
 * @api
 */
final readonly class RiskChallengeVerifier
{
    private const int MAX_RESPONSE_BYTES = 16_384;

    private ClientProtocolPolicy $protocol;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private string $endpoint,
        #[\SensitiveParameter]
        private string $secret,
        private string $hostname,
        private string $tenant,
        private string $action,
        private float $timeoutSeconds = 3.0,
    ) {
        $parts = parse_url($endpoint);
        if (strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint) !== 0
            || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
            || !self::dnsName($parts['host']) || !self::dnsName($hostname)
            || strtolower($hostname) !== $hostname
            || $secret === '' || strlen($secret) > 4096
            || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $tenant) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $action) !== 1
            || !is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 10
        ) {
            throw new \InvalidArgumentException('Invalid challenge provider configuration.');
        }
        $this->protocol = new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
            destinationPolicy: new ClientDestinationPolicy([$endpoint]),
        );
    }

    /**
     * true = accepted, false = rejected, null = unavailable. Use the active
     * Runwire scope and a dispatcher attached to its owner loop. No retry or
     * redirect is attempted; cancellation of the parent remains cancellation.
     *
     * @throws \Infocyph\Runwire\Exception\CancelledException
     */
    public function verify(
        CoroutineScope $scope,
        HttpOutboundDispatcher $dispatcher,
        RiskContext $context,
        #[\SensitiveParameter]
        string $proof,
    ): ?bool {
        $scope->cancellation()->throwIfCancelled();
        $source = null;
        $handle = null;
        try {
            $start = hrtime(true);
            if (!is_int($start)) {
                return null;
            }
            $deadline = RequestDeadline::afterSeconds($this->timeoutSeconds, $start);
            $source = CancellationSource::linked($scope->cancellation(), $deadline);
            $request = $this->prepare($context, $proof, $source->token(), $deadline);
            if ($request === null) {
                return false;
            }

            $deferred = $scope->deferred();
            $future = $deferred->future();
            $status = 0;
            $payload = '';
            $invalid = false;
            $handle = $dispatcher->send(
                $request,
                static function (int $code, Headers $headers) use (&$status, &$invalid, $source): void {
                    $status = $code;
                    $length = $headers->first('content-length');
                    if ($code !== 200 || ($length !== null && (strlen($length) > 5
                        || preg_match('/\A[0-9]+\z/D', $length) !== 1
                        || (int) $length > self::MAX_RESPONSE_BYTES))
                    ) {
                        $invalid = true;
                        $source->cancel(CancellationReason::HOST_CANCELLED);
                    }
                },
                static function (string $chunk) use (&$payload, &$invalid, $source): void {
                    if ($invalid || strlen($chunk) > self::MAX_RESPONSE_BYTES - strlen($payload)) {
                        $invalid = true;
                        $source->cancel(CancellationReason::HOST_CANCELLED);

                        return;
                    }
                    $payload .= $chunk;
                },
                function (?Throwable $error) use ($deferred, $future, &$status, &$payload, &$invalid): void {
                    if (!$future->isComplete()) {
                        $deferred->resolve($error === null && !$invalid ? $this->interpret($status, $payload) : null);
                    }
                },
                new DialOptions(
                    connectTimeoutSeconds: $this->timeoutSeconds,
                    deadline: $deadline,
                    cancellation: $source->token(),
                    addressPolicy: new OutboundAddressPolicy(),
                ),
            );
            $result = $future->await();
            $scope->cancellation()->throwIfCancelled();

            return !$deadline->expired() && is_bool($result) ? $result : null;
        } catch (Throwable) {
            $scope->cancellation()->throwIfCancelled();

            return null;
        } finally {
            $handle?->cancel();
            $source?->dispose();
        }
    }

    /**
     * Native upload envelope; the exact configured URI is authorized by
     * Runwire before DNS, including its path and any fixed query.
     *
     * @internal
     * @throws \InvalidArgumentException
     */
    public function prepare(
        RiskContext $context,
        #[\SensitiveParameter]
        string $proof,
        CancellationToken $token,
        RequestDeadline $deadline,
    ): ?ClientRequest {
        if ($context->tenant !== $this->tenant || $context->purpose !== $this->action
            || $proof === '' || strlen($proof) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $proof) !== 0
        ) {
            return null;
        }
        $payload = http_build_query([
            'secret' => $this->secret,
            'response' => $proof,
            'remoteip' => $context->ip,
        ], '', '&', PHP_QUERY_RFC3986);
        $body = new class ($payload) implements ClientBodySourceInterface {
            private int $offset = 0;

            public function __construct(#[\SensitiveParameter] private readonly string $payload) {}

            #[\Override]
            public function eof(): bool
            {
                return $this->offset === strlen($this->payload);
            }

            #[\Override]
            public function read(int $maxBytes = PHP_INT_MAX): string
            {
                if ($maxBytes <= 0) {
                    return '';
                }
                $bytes = substr($this->payload, $this->offset, $maxBytes);
                $this->offset += strlen($bytes);

                return $bytes;
            }

            #[\Override]
            public function trailers(): ?Headers
            {
                return null;
            }
        };

        return new ClientRequest(
            'POST',
            $this->endpoint,
            Headers::fromArray([
                'accept' => 'application/json',
                'content-type' => 'application/x-www-form-urlencoded',
                'cache-control' => 'no-store',
            ]),
            $body,
            $this->protocol,
            $token,
            $deadline,
        );
    }

    /**
     * Parse only a bounded provider response; never return raw provider text.
     *
     * @internal
     */
    public function interpret(int $status, string $payload): ?bool
    {
        if ($status !== 200 || strlen($payload) > self::MAX_RESPONSE_BYTES) {
            return null;
        }
        try {
            $response = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($response) || !is_bool($response['success'] ?? null)) {
            return null;
        }

        return $response['success'] === true
            && ($response['hostname'] ?? null) === $this->hostname
            && ($response['action'] ?? null) === $this->action
            && ($response['cdata'] ?? null) === $this->tenant;
    }

    private static function dnsName(string $value): bool
    {
        return strlen($value) <= 253 && str_contains($value, '.')
            && !str_ends_with($value, '.')
            && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && filter_var($value, FILTER_VALIDATE_IP) === false;
    }
}
