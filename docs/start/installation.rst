.. _installation:

============================
Installation & prerequisites
============================

Infbyte requires PHP 8.4 or newer and Composer 2. The PHP extensions and
external services needed depend on which optional capabilities are enabled.

Existing published application skeleton (Foundation 3)
======================================================

Today the Infbyte project's `main` branch declares
`"infocyph/foundation": "^3.0.1"`. To install that **Foundation 3**
starter, run:

.. code-block:: bash

   composer create-project infocyph/infbyte my-app
   cd my-app
   php infbyte serve

This sequence is **not** a Foundation 4 installation procedure.

Foundation 4 candidate (development only)
=========================================

**Infbyte PR #11 already contains the Foundation 4 application candidate** on
the ``feature/infbyte-4`` branch. Its Composer manifest temporarily requires
the unpublished Foundation ``dev-feature/foundation-3`` from GitHub, and its
older green CI runs do not certify the latest Foundation commit.

Use :doc:`foundation4-candidate` for the actual clone, dependency resolution,
CLI, native Webrick routes, and current compatibility caveats. Do **not** use
``composer create-project infocyph/infbyte`` as a Foundation 4 installation.

The underlying Foundation package is independently installable via Composer,
but ``composer require infocyph/foundation`` does **not** create an Infbyte
application skeleton, and the currently published package is not Foundation 4.

Next, see :doc:`first-route` for the existing skeleton's example route.

Learn the layout in :doc:`directory-structure` and start the development
server using :doc:`development-server`.
