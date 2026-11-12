.. _installation:

===========================
Installation & prerequisites
===========================

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

Foundation 4 development branch
===============================

The Foundation 4 source currently lives in Foundation PR #20 and is not
represented by the public Infbyte 3 installation command. Foundation is also
independently installable as the underlying PHP package:

.. code-block:: bash

   composer require infocyph/foundation

That command installs the package, **not** a populated Infbyte application.
Do not infer the currently published package version from this example.
Wait for a compatible downstream Infbyte skeleton before using this guide
as a Foundation 4 application-creation tutorial.

Next, see :doc:`first-route` for the existing skeleton's example route.
