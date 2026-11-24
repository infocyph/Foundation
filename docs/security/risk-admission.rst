.. _risk-admission-guide:

Credential-stuffing risk admission
==================================

Foundation 4's opt-in risk admission is an application-owned protection for
selected login routes, not a replacement for authentication, authorization,
CSRF, account lockout, MFA or an edge rate limit. It depends on the released
Webrick 6.1, Runwire 3.1 and CacheLayer 4.0.3 compatibility floors. It is under
qualification on the development branch; do not treat this guide as release
certification.

Trust boundary
--------------

The immediate transport peer is obtained from the actual server connection,
not from a request header. Configure exact trusted edge networks, and have
the edge remove incoming copies of its private client-IP, JA4 and ASN headers
before inserting independently measured values. Restrict origin ingress to
those edge instances (prefer authenticated transport as well). A proxy CIDR
alone does not prove that an arbitrary header is authentic.

Use no JA4 signal when the selected CDN/proxy does not actually measure it.
Nginx and Caddy do not magically compute JA4 by forwarding a header. If
there is an attested signal, supply the exact single value; reject ambiguous
or malformed signals. Tenant and purpose are application-selected constants,
not arbitrary request parameters. IP prefixes and fingerprints are risk
signals, never identity credentials.

Application composition
-----------------------

Create these objects only for protected authentication endpoints, using
server-side secret material and a deliberately selected storage topology.
A single application container may reuse immutable policy and service
objects. Do not attach the risk action to every route. Existing ordinary
authentication calls need no risk configuration.

The following is the composition boundary in a protected login controller.
The application supplies the selected native CacheLayer cache instances,
validated login request, actual transport peer, compiled trusted networks,
and independently collected edge signals. Never pass challenge tokens or
raw cookies in LoginRequest audit context.

.. code-block:: php

   use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
   use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
   use Infocyph\Foundation\Security\Risk\RiskAuthority;
   use Infocyph\Foundation\Security\Risk\RiskContext;
   use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
   use Infocyph\Foundation\Security\Risk\RiskLogin;
   use Infocyph\Foundation\Security\Risk\RiskPolicy;
   use Infocyph\Foundation\Security\Risk\RiskProofStore;
   use Infocyph\Foundation\Security\Risk\RiskTelemetry;
   use Infocyph\Webrick\Response\Response;

   $telemetry = new RiskTelemetry($telemetryCache, $telemetryKey, shared: true);
   $authority = new RiskAuthority($securityCache, $authorityKey, shared: true);
   $proofs = new RiskProofStore($authority, 'login-provider');
   $bulkhead = new RiskHashBulkhead(
       $authority,
       'auth-workers',
       8,
       nonEvictingIsolatedPool: true,
   );
   $risk = new RiskLogin(
       $app->make(Authenticator::class),
       new RiskPolicy(),
       $telemetry,
       $proofs,
       bulkhead: $bulkhead,
   );

   $context = RiskContext::fromTransport(
       'tenant-a',
       'login',
       $actualPeer,
       $trustedEdgeNetworks,
       $edgeClientIps,
       $edgeFingerprints,
       $edgeAsns,
       $userAgents,
   );

   return $risk->handle(
       $request,
       $validatedLogin,
       $context,
       time(),
       static fn(LoginResult $result): Response => Response::json(
           ['ok' => $result->authenticated()],
           $result->authenticated() ? 200 : 401,
       ),
       clearance: $validatedClearance,
   );

Here the response example deliberately does not distinguish an absent
account from a bad password. A browser application must also preserve its
existing session creation/rotation and CSRF contract. Configure trusted
devices through the existing DeviceManager and RiskDeviceTrust, optionally
passing a server-resolved canonical account and a validated device cookie
to the risk action. Never use a client-provided account ID to grant relief.
Construct RiskDeviceTrust with the existing authoritative SessionStoreInterface
and DeviceManager. Issue the cookie only with a successful login and the
host-verified device ID from an independent possession/enrollment check,
not a device ID supplied in login context. Pass that verified ID to
RiskDeviceTrust::issueCookie; absent or mismatched possession, revoked or
expired session, and device-store errors deny issuance. Revocation of
an issued score-relief token follows the authoritative device store;
the signed token is not a session credential. Observational feedback runs
after authoritative lockout and audit; a telemetry exception cannot undo
an authentication outcome.

The policy returns allow, challenge, deny or unavailable through Webrick's
native admission middleware. Rejection status codes are 429, 403 and 503;
responses are not cacheable. Shadow mode performs observation only:
it does not spend clearance, write feedback or acquire capacity. Enable
enforcement only after validating the policy on representative traffic.

Provider proof and clearance
----------------------------

The optional RiskChallengeVerifier is constructed with a fixed HTTPS
provider endpoint, server-owned secret, expected hostname, tenant and
action. It uses Runwire's exact URI authorization, a public-IP destination
policy, bounded response, deadline and linked cancellation. No redirects
or implicit retry are accepted. The implemented wire contract is
Turnstile-compatible (success, hostname, action, cdata), using native H1
outbound TLS; other provider protocols are not implicitly supported.

Use the active Runwire CoroutineScope and dispatcher owned by its event
loop for the verification endpoint. Only when verification returns exactly
true may the application call RiskProofStore::issueClearance with the
same context, normalized login identifier, verified proof and current
time. Return the opaque clearance via the application's HTTPS challenge
flow. False rejects; null means unavailable. Never retry the whole
verification/claim transaction automatically. The challenge endpoint itself
requires edge throttling and appropriate CSRF protection. A proof accepted
by a provider is not authorization to authenticate.

One clearance is consumed before the password check when a challenge is
required. Wrong credentials do not restore it. Deny overrides clearance.
Configure proof retention to exceed the actual provider's accepted token
lifetime, including clock/propagation margin. Keep the provider identity
and authority key stable across hosts and deployment generations.

Storage and CPU capacity
------------------------

.. warning::

   A CacheLayer adapter name does not qualify a distributed authority.
   Replay protection, clearance and capacity require a single genuinely
   shared consistency domain, native atomic CAS/claim/consume, payload
   integrity and fail-closed behavior. Local arrays are suitable only for
   isolated test behavior. Do not use an evicting or asynchronously
   divergent replica as the security authority.

Telemetry is best effort and may report incomplete observations. Its
failure counters are bounded fixed windows; no raw identifiers, IPs or
fingerprints are used as physical cache keys. Proof/clearance operations
instead fail closed when authority is unavailable. Use distinct
deployment-managed keys for telemetry, authority and device signing.

The hash bulkhead holds an owner-matched slot through synchronous
authentication work. Its finite scan has no unbounded waiter queue.
Missing capacity returns 503. It does not launch or interrupt workers:
configure a dedicated, bounded FPM pool or separately supervised
authentication service; the application must not run blocking password
hashes in a shared coroutine/event-loop worker and call that isolation.
Qualify cancellation, process exit, backpressure, memory limits and
shutdown on the actual host.

Provision slots once with RiskHashBulkhead::provisionDrainedPool only
during a controlled, completely drained deployment. The operator's
non-evicting/isolated-pool assertion is not runtime proof of topology.
Slots do not expire while work might still be running. A process crash
can strand capacity, deliberately failing closed. Verify every worker
using the pool is stopped before recovering occupied slots through an
offline operator procedure. Never clear the security cache or
automatically recreate capacity following cache loss. A key or pool
identity change must also occur only after a verified drain.

Edge and deployment integration
-------------------------------

* Nginx: match the protected login/challenge routes explicitly and forward
  them to a separate bounded authentication FPM pool or upstream; ordinary
  endpoints retain the ordinary upstream. Enforce origin access
  restrictions and body/rate limits before forwarding.
* Caddy: use specific route/handle matchers for the protected paths and a
  separate authentication upstream or php_fastcgi pool. Preserve a single
  request-to-response path and the same trusted-proxy restrictions.
* CDN: enable origin restriction, remove client-supplied private signal
  headers, and populate only signals the CDN itself measures. Do not
  accept equivalent client-direct requests at the origin.
* Runwire: do not replace host lifecycle or treat cooperative cancellation
  as proof that synchronous password work has stopped. Qualify the
  separate worker pool and shared capacity store as one deployment.

For migration, resolve and test the published dependency floors first.
Deploy in shadow, compare protected and ordinary-route cost separately,
then enforce only on the explicitly selected endpoints. Monitor safe
reason codes, 429/403/503 rates, backend health and real worker saturation;
never log password, proof, token, raw fingerprint or cookie values.
Rollback enforcement by draining the protected worker pool and reverting
its application route composition; preserve live proof/clearance and
device revocation state and do not reset slots while an owner may run.

Acceptance before production
----------------------------

The source tests are necessary, not sufficient. Require the full PHP
8.4–8.6 supported matrix, strict analysis and documentation, real shared
backend contention and failure behavior, provider TLS and cancellation
negative cases, and isolated host abort/drain qualification. Measure
protected-login throughput and latency separately from unprotected
routes, including concurrent rejected/allowed requests and at least a
30-minute soak. Record actual resolved package versions and the tested
source revision. Do not enable production enforcement based on an
unfinished CI run or a local-memory test.
