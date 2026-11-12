.. _getting-started-overview:

================
What is Infbyte?
================

Infbyte is the application skeleton and developer experience built on top
of the `infocyph/foundation` package. Foundation composes application
configuration, service providers, lifecycle boundaries, and explicit web,
CLI, worker, and scheduler runtimes. Specialist Infocyph libraries retain
their native domain implementations.

The framework is designed to keep the application small: capabilities are
selected intentionally, and a simple HTTP endpoint should not instantiate
services it does not use.

How Infbyte relates to Foundation
=================================

* **Infbyte** supplies the application layout, application configuration,
  executable, routes, and application code.
* **Foundation** provides application composition, policy, CLI integration,
  and trusted release generation.
* **Native packages** provide the underlying HTTP, DI, data, crypto,
  validation, messaging, and runtime mechanisms.

Start with :doc:`installation`. Read :doc:`../internals/native-boundaries`
when deciding which native package owns a capability.

Current version status
======================

This website is being prepared for Foundation 4. The current Infbyte
application skeleton on GitHub `main` still targets Foundation 3. Examples
specific to Foundation 4 must be verified against the updated skeleton before
they become published quick-start instructions.
