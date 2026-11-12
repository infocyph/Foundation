.. _documentation-maintenance:

Maintaining the Infbyte manual
==============================

This manual is the **Infbyte developer documentation**, maintained in the
Foundation repository for publication. A hosted project is not yet verified. Its public identity must not be confused with the
Foundation package itself. It explains how developers build applications;
Foundation's older engineering Markdown guides remain implementation references
during the RST migration.

Version and compatibility policy
================================

The public Infbyte application on GitHub ``main`` requires
Foundation ``^3.0.1``. An **Infbyte 4 candidate already exists** on
``feature/infbyte-4`` / draft PR #11, but requires an unreleased Foundation
branch. The RST manual documents the **Foundation 4 development contract**.
Until a matching stable Infbyte/Foundation release is available:

* Mark installation and first-route examples from the published starter as
  **Infbyte 3**, not Infbyte 4.
* Never imply that ``composer create-project infocyph/infbyte`` currently
  produces a Foundation 4 application.
* Distinguish checked source APIs from integration-verified examples and
  published deployment instructions.
* Only remove the development warning after an independently tested compatible
  starter, publication checklist and actual Read the Docs version are ready.
* Keep the source branch visible in technical references; use versioned
  documentation targets when real releases become available.

Sources of truth
================

Confirm instructions against the relevant public API and tested contracts:
Foundation owns application composition, runtime graphs, module selection and
trusted generation handling. Webrick owns HTTP routing and response behavior;
InterMix owns DI; Runwire owns host lifecycle; DBLayer, CacheLayer, Omnibus,
Pathwise, ReqShield, TalkingBytes and other packages own their specialist
mechanisms.

Do not invent Laravel-compatible facades, view engines, Active Record models,
route discovery, module installation aliases or command flags for convenience.
Use Laravel's instructional progression, not unsupported API parity.

Page content and examples
=========================

A major guide should answer:

1. **What and when:** the developer task, common use case and prerequisites.
2. **Minimal path:** the smallest accurate command or code example.
3. **Detailed usage:** configuration, relevant APIs and runtime selection.
4. **Why it works:** ownership and lifecycle boundaries in plain language.
5. **Failures:** realistic validation, missing dependency and recovery cases.
6. **Production notes:** security, state isolation and performance costs.
7. **Next steps:** internal links to related guides and the native manual.

Use concise paragraphs before technical tables. Each publicly documented
optional feature must name its *qualified Foundation 4 ID* and native package
owner. Do not recommend a process-local store for production replay, durable
queue coordination or distributed session locking.

Validation strategy
===================

Every RST page must appear in a Sphinx ``toctree`` or be intentionally
excluded. Internal references must resolve, RST headings must have matching
adornments and code highlighting must use supported lexers.

The repository's strict documentation build is:

.. code-block:: bash

   python -m pip install -r docs/requirements.txt
   python -m sphinx -b html -n -W --keep-going docs docs/_build/html

Complete PHP source files maintained only for documentation should be stored
as ``.php.txt`` assets and included using Sphinx
``literalinclude``, with explicit ``:language: php``. They are linted
with ``php -l`` but must not become runtime/source-analysis classes.
Avoid weakening Psalm, PHPStan, PHPCS, Pint or the skip-directive policy
to accommodate illustrative classes.

Complete PHP code blocks in RST that start with ``<?php`` are syntax-checked
by ``docs/tools/lint_php_blocks.py``. Code fragments without the PHP open tag
are **contextual snippets**; they need manual API review. A PHP syntax pass is
not a claim that the example runs against an unpublished Infbyte 4 skeleton.

Documented public-contract drift guard
======================================

The documentation workflow also runs
``python docs/tools/check_public_contracts.py``. This fails the docs job when
named public Foundation methods, the approved three-module/eight-feature
catalog or expected Core/optional config templates drift away from the
reference pages. It is intentionally a narrow source-declaration check,
not a PHP parser or a runtime/integration guarantee.

Maintain its checked contract list alongside reference updates; do not
paper over deleted methods by deleting their guard entries without
reviewing the affected public documentation.

Publication and handoff
=======================

Before labelling a documentation version stable, verify:

* strict HTML build and internal cross-references are green;
* all complete PHP examples pass lint and significant examples pass runtime
  integration against the released matching Infbyte skeleton;
* official native library links, package versions and feature names are current;
* production host/deployment/rollback instructions match actual validated paths;
* external Read the Docs project and version routing are configured and the
  hosted build succeeds (local Sphinx does not prove publication);
* previous documentation versions remain accessible after the upgrade.

Do not use documentation CI to waive Foundation's separate sustained runtime,
performance, security or release source-qualification gates.

For the running implementation ledger, see the
`Infbyte documentation development plan
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/infbyte-documentation-development-plan.md>`_.

For the explicit external blocker checklist, see
:doc:`publication-readiness`.
