.. _publication-readiness:

================================
Publication and compatibility
================================

**Infbyte** is the public documentation identity of the framework
application experience; **Foundation** is the PHP package and source
repository that houses this manual.

This is the **Foundation 4 development manual**, not a notice that
Foundation 4 has been released. The current upstream Infbyte
application skeleton on ``main`` still requires
``infocyph/foundation: ^3.0.1``. This was verified from its
``composer.json`` during the documentation audit.

What has been verified
======================

The Foundation development branch contains an Infbyte-branded Sphinx
manual, navigation and Read the Docs configuration. The documentation
CI builds HTML with warnings treated as errors, validates complete
PHP example code blocks with PHP 8.4 syntax checks, and runs a
lightweight public API/feature drift check.

Previous documentation batches (D1 through D8-A) have recorded
successful Sphinx jobs in the
`documentation development plan
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/infbyte-documentation-development-plan.md>`_.
D8-B records its own check once the exact-head job has completed.

The source audit checks the **declared existence** of reference
methods, configuration templates and qualified optional feature IDs.
It is not a PHP type checker or a cross-package integration test.

What is still blocked
=====================

.. list-table::
   :header-rows: 1
   :widths: 25 43 32

   * - Gate
     - Required evidence
     - Current state
   * - Infbyte starter compatibility
     - The actual Infbyte application skeleton targets a released,
       compatible Foundation 4 package and its CLI/route/provisioning
       behavior is validated end to end
     - **Blocked:** public skeleton still requires Foundation 3
   * - Foundation release qualification
     - Exact final source/vendor SHA, security/lifecycle readiness,
       retained-state rehearsal and required sustained host comparison
     - **Independent release gate; not certified by docs**
   * - Hosted Read the Docs project
     - Infbyte-branded project linked to this source, version routing
       configured, hosted Sphinx HTML build succeeds
     - **Not verified:** repository config alone does not publish a site
   * - Runnable consumer examples
     - Tutorial and reference examples tested against the matching
       application skeleton and native packages
     - **Only source/syntax-checked so far**
   * - External link health
     - Official owner docs and outgoing URLs audited against an
       actual published version
     - **Manual/publication check remains**
   * - Stable documentation version
     - Released Foundation 4 contract, matching user-facing
       Infbyte version and supported migration path
     - **Do not label stable yet**

A successful docs CI run does **not** permit merging PR #20, tagging a
release or clearing unrelated benchmark and migration gates.

Local and CI verification
=========================

Inside the Foundation repository, install the documentation build
dependencies and run the strict build:

.. code-block:: bash

   python -m pip install -r docs/requirements.txt
   python -m sphinx -b html -n -W --keep-going docs docs/_build/html
   python docs/tools/check_public_contracts.py
   python docs/tools/lint_php_blocks.py

``lint_php_blocks.py`` requires a PHP CLI executable. The GitHub
documentation workflow selects PHP 8.4. Complete example assets with
``.php.txt`` extensions are syntax-checked explicitly by the same
workflow.

These commands check navigation/heading and local reference correctness,
source method/feature drift and complete PHP syntax. They do **not**
execute the user's application, contact remote backends or guarantee
that external links are reachable.

A manual external-link check can use:

.. code-block:: bash

   python -m sphinx -b linkcheck docs docs/_build/linkcheck

Network, authentication, rate limits and temporarily unreachable
external sites can cause false positives. Review those results with
the owning package documentation before treating a failure as a
broken public contract.

Publish the matching application first
======================================

A downstream Infbyte 4 starter migration should be a separately
reviewed and tested change, **not** an automatic dependency edit
performed by documentation work. It should cover:

1. Composer Foundation major constraint, platform requirements and
   native package compatibility;
2. Bootstrap, route file registry and application service-provider
   topology;
3. Qualified optional feature IDs and configuration publication;
4. Command/scheduling/worker execution and native service ownership;
5. Generated web/CLI/worker/scheduler release, external manifest trust,
   and safe source-to-release activation;
6. HTTP, auth/session/CSRF, database, message and filesystem integration;
7. Migration, retained-state and rollback rehearsal using selected
   production-like backends.

Once the compatible starter is released, repeat tutorial commands on
that *exact* tagged application and dependency lock and capture
the results. Do not copy instructions from the Foundation 3
starter and describe them as tested Foundation 4 usage.

Read the Docs handoff
=====================

The repository contains ``.readthedocs.yaml`` and
``docs/conf.py`` for the Infbyte-branded Sphinx project.

The publication administrator must connect the actual Infbyte
Read the Docs project to the correct Foundation repository/branch,
configure the intended public version alias, and verify the
hosted build and navigation. Decide explicitly whether the
development branch should be visible as a preview before release.

When a stable version is ready:

* retain previous published documentation versions and clear labels
  for incompatible majors;
* point examples/source links to the matching immutable tag instead
  of a moving branch;
* remove the Foundation 4 development warning **only** after
  compatible starter and release evidence exists;
* verify theme links, canonical project identity and accessible
  mobile/desktop navigation on the published build;
* run an external-link audit and test representative code/CLI
  examples against the released application.

See :doc:`maintenance`,
:doc:`../deployment/overview`,
:doc:`../tutorials/release-and-rollback`, and
:doc:`../start/installation`.
