.. _authorization-guide:

Authorization
=============

Authentication answers **who the caller is**. Authorization decides
**whether that caller may perform an action**.

Foundation 4 composes roles, permissions, gate/policy checks,
delegation and step-up requirements into the application security
boundary. It is not a replacement for feature-specific validation.

Guard selected routes
---------------------

Foundation 4 includes middleware aliases ``auth``, ``role``,
``permission``, and ``policy``. A principal must be resolved
before these authorization checks can consume it. Do not apply
identity-dependent authorization middleware to a route that cannot
establish a current principal.

Keep route-level policy explicit. A permission granted for one
resource or account does not necessarily authorize an unrelated
resource with the same route parameter.

Obtain the native service
-------------------------

From an application context with an ``Application $app`` instance,
the typed gateway provides the configured authorizer:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);
   $authorizer = $auth->authorizer();
   $gate = $auth->gate();

These are Foundation 4 contracts. The concrete policy and grant
configuration depends on the application's selected auth backend and
cannot be inferred from the presence of a Composer package.

Security and persistent runtimes
--------------------------------

Do not store a current principal in a singleton that survives a web
request, job, or persistent-host execution unit. Foundation clears
execution-scoped principal state on scope exit.

Fail closed when a required role, permission, or backend check cannot
be completed. Do not treat unavailable security storage as an empty,
successful authorization decision.

See the `complete authorization contracts
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/authentication.md>`_
and :doc:`authentication`.
