.. _publication-readiness:

================================
Publication and compatibility
================================

**Infbyte** is the public documentation identity of the framework
application experience; **Foundation** is the PHP package and source
repository that houses this manual.

This is the **Foundation 4 development manual**, not a release
announcement. Infbyte's published ``main`` starter still requires
Foundation 3 (``^3.0.1``); Infbyte PR #11 on
``feature/infbyte-4`` is an unpublished candidate
requiring ``dev-feature/foundation-3``.

The Foundation PR currently verifies that candidate on PHP 8.4/8.5
through a **read-only, exact-source Core compatibility workflow**.
As of November 13, 2026 the corresponding Infbyte PR #11 full
Security & Standards and Candidate Integration workflows have
**failures**, and neither PR is merged or published. The Core probe
does not replace full consumer acceptance or a stable Composer cutover.


What has been verified
======================

The Foundation development branch contains an Infbyte-branded Sphinx
manual, navigation and Read the Docs configuration. The documentation
CI builds HTML with warnings treated as errors, validates complete
PHP example code blocks with PHP 8.4 syntax checks, and runs a
lightweight public API/feature drift check.

The Infbyte manual's planned authoring work was completed through D15-B.
The final content commit ``81834190c863918425b2f4d9b2603fd6441ddea4``
passed strict Sphinx, 118 declared method checks, 12 configuration-template
checks, 90 complete PHP snippets and the read-only Infbyte candidate
compatibility workflow on PHP 8.4/8.5. The completed development tracker is
available in Git history; it is intentionally not shipped as an active plan.

Current-source read-only Core candidate compatibility has passed, but the
full downstream Infbyte PR #11 security/integration matrix is still failing.
Documentation QA and consumer qualification must be reported separately.

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
     - **Candidate exists in Infbyte PR #11**, but stable cutover and fresh
       exact-source integration remain blocked; public ``main`` is Foundation 3.
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
     - **Manual:** source/syntax-checked; **application:** current-source Core
       smoke passed on PHP 8.4/8.5, but full optional-feature/host matrix and
       stable consumer examples remain pending
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

The downstream Infbyte 4 migration **already has an implementation
candidate** in Infbyte PR #11. Its prepared stable cutover must be
separately reviewed and tested, **not** an automatic dependency edit
performed by documentation work. The remaining verification includes:

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

The Core candidate is regularly checked against the exact Foundation PR source, but
Infbyte's own full candidate/security jobs remain failing and the stable cutover
is incomplete. Reconcile the current failing jobs and rerun tutorial commands
against the eventual *exact* tagged application and dependency lock. Do not copy instructions from the Foundation 3
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

See :doc:`../start/foundation4-candidate`, :doc:`maintenance`,
:doc:`../deployment/overview`,
:doc:`../tutorials/release-and-rollback`, and
:doc:`../start/installation`.
