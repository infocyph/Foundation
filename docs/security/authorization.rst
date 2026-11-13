.. _authorization-guide:

Authorization, roles and permissions in Infbyte
===============================================

**Authentication** answers who is calling.
**Authorization** decides whether that caller
may perform a particular operation on a
particular application resource.

Foundation Core composes principals, gates,
roles, permissions, delegation and step-up
policy. The underlying storage and selected
security mechanisms are configured by your
**Infbyte application**, not through a
Laravel ``Gate`` facade.

Start with the authenticated principal
--------------------------------------

An authorization decision needs a reliable
current identity when the route is protected.
A health endpoint needs neither identity
nor database-backed role queries.

On a selected Webrick route, the Foundation
``resolve-auth`` middleware establishes
a principal. The ``auth`` policy rejects
anonymous callers when required.

For an authenticated browser route, the
``web-auth`` preset expands to:

.. code-block:: text

   session -> csrf -> resolve-auth -> auth

Do not apply a role or permission check
before the principal is resolved. Browser
session and token-based API authentication
are distinct flows and must be configured
deliberately.

Access the typed authorization services
---------------------------------------

In an application service with an existing
Foundation ``Application $app``:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);
   $authorizer = $auth->authorizer();
   $gate = $auth->gate();
   $roles = $auth->roles();
   $permissions = $auth->permissions();

These are source-verified
``AuthServices`` public accessors. Their
concrete decision and persistence methods
belong to Foundation's selected auth
contract; don't substitute Laravel's
``Gate::allows()`` or policy discovery.

A compiled production graph can provide
these services lazily; a simple public
route should not construct them eagerly.

Authorization happens at two levels
-----------------------------------

**Route-level** middleware may reject
a request before its controller runs.
Foundation includes aliases for
``auth``, ``role``, ``permission``
and ``policy`` when those integrations
are configured in the selected route graph.

**Resource-level** authorization checks
the actual entity and operation. For
example, a valid route parameter
``/api/invoices/{id:int}`` only proves
a numeric ID matched Webrick's route
constraint. It does **not** prove that
the caller owns the invoice or has
permission to view the selected tenant.

Resolve the resource through your own
application service and verify identity,
tenant context and permitted operation
**before** returning its representation.

Roles and permissions
---------------------

A role groups intended grants; a permission
describes an operation. The exact grant
persistence depends on the selected
account/authorization backend.

Application routes should not infer
a user's role from a client-provided
header, submitted JSON, hidden form field
or unsigned cookie. Identity and grants
must come from trusted principal sources
and authoritative application state.

Avoid giving the same role unrestricted
access across multiple tenants unless
that is an explicit reviewed policy.

Step-up and sensitive actions
-----------------------------

High-risk account actions may require
recent authentication, additional
verification, MFA or a specific delegation
scope. Foundation offers a source-checked
``AuthServices::stepUp()`` service for
such policy and middleware aliases such
as ``recent``.

Do not treat a recently authenticated
browser session as equivalent to a
verified second factor or passkey
assertion unless the configured policy
explicitly establishes that assurance.

Test both grant and denial behavior
-----------------------------------

An adequate authorization test covers:

1. Anonymous caller rejected on a protected route.
2. Authenticated user allowed to access own resource.
3. Authenticated user denied on another user's record.
4. Missing role or permission rejects access.
5. Valid route parameter still denied for
   cross-tenant resource ownership.
6. A backend failure does not silently grant access.
7. Delegation or impersonation does not outlive
   its permitted scope.
8. Elevated/step-up permission expires as configured.
9. A subsequent request in the same persistent
   process cannot reuse a prior principal.

The chosen test backend and real production
account/permission store may behave differently.
Do not treat a fake grant registry as evidence
that multi-host revocation or role updates
are correctly propagated.

Authorization and validation are separate
-----------------------------------------

Validation checks **shape and content**:
an integer ID, email, valid date or allowed
input option. Authorization checks **rights**:
which principal may read or modify the
identified object.

A request can be perfectly valid yet
unauthorized. Keep those conditions
distinct in HTTP status mapping and
safe error descriptions.

Use ReqShield for input validation only
when ``security.validation`` is selected;
read :doc:`../http/validation` for its
typed-value contract.

Failure policy and operational safety
-------------------------------------

If a required authorization store is
unavailable, don't grant access by assuming
an empty or successful permission list.
Make errors safe and observable without
disclosing another user's identity,
private roles or internal provider details.

Use sensitive-key redaction and record
necessary security audit identifiers.
Do not capture full bearer tokens or
password-reset links in logs.

Keep principal state execution-scoped.
A process-wide ``static`` current user
is unsafe under persistent Runwire workers
and concurrent callbacks.

Common mistakes
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Mistake
     - Consequence
     - Correct boundary
   * - Trusting a route ``:int`` constraint
     - Cross-account read
     - Authorize the actual resource
   * - Applying roles before principal resolution
     - Incorrect or missing security context
     - Select correct middleware order
   * - Trusting submitted ``role`` value
     - Privilege escalation
     - Use authoritative role/grant store
   * - Treating validation as permission
     - Valid but unauthorized write
     - Enforce independent authorization
   * - Caching principal globally
     - Cross-request identity leak
     - Use request/operation scopes
   * - Failing open on backend error
     - Unauthorized data exposure
     - Reject required checks on failure

Further reading
---------------

See :doc:`authentication`,
:doc:`sessions-csrf`,
:doc:`../testing/http` and the
detailed Foundation authorization source guide:

https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/authentication.md
