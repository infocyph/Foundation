.. _onboarding-configuration:

Configuring an application
==========================

Infbyte configuration is application-owned. Foundation composes it with
its defaults and selected preset, and activates only explicitly requested
capabilities. This page describes **Foundation 4 development contracts**;
the following file example comes from the current **Foundation 3 starter**.

Start with the application settings
-----------------------------------

The skeleton's ``config/app.php`` contains an application name,
environment, debug policy, URL, capability topology, and development
container options. This is a shortened illustration:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'name' => env_string('APP_NAME', 'Infbyte'),
       'debug' => env_bool('APP_DEBUG', true),
       'url' => env_string('APP_URL', 'http://localhost'),
   ];

Do not replace the complete skeleton's configuration with this excerpt.

Environment variables in the current starter's ``.env.example`` include:

.. code-block:: text

   APP_NAME=Infbyte
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost
   APP_CAPABILITIES=

Do not commit secrets. Foundation configuration helpers include
``env()``, ``env_bool()``, ``env_int()``, and ``env_string()``.
For Foundation 4 development/build composition the precedence order is
framework defaults, preset, project configuration, then inline bootstrap
overrides. Production does not reread those inputs for each request.

Select optional capabilities deliberately
-----------------------------------------

Foundation 4 uses qualified optional feature names. Example commands:

.. code-block:: bash

   php infbyte module:list
   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:doctor data.database

The ``data.database`` feature is backed by DBLayer. The current Infbyte
3 starter still uses the older module catalog. **Do not copy these
Foundation 4 module commands into the current Infbyte 3 application.**

Installed Composer packages do not imply an enabled capability.
Review ``app.capabilities`` and optional ``modules.features``
alongside the selected packages and published configuration.

Validate and compile
--------------------

In a compatible Foundation 4 application, use:

.. code-block:: bash

   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte app:ready

Development configuration caches are ArrayKit-native ``single`` or
``sharded`` layouts:

.. code-block:: bash

   php infbyte config:cache
   php infbyte config:clear

These are different from immutable production release generations. To
compile and inspect a trusted generation:

.. code-block:: bash

   php infbyte optimize
   php infbyte optimize:report

If a configured integration cannot resolve, check selected capabilities
and dependencies first. Do not disable release verification or add
unrelated global middleware as a workaround.

The authoritative key reference is the
`Foundation configuration guide <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/configuration.md>`_.
