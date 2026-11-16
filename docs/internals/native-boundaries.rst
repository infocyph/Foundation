.. _native-ownership:

===========================
Native library boundaries
===========================

Foundation is an application composition layer. It does not reimplement
the specialist engines supplied by its dependencies.

======================  =========================================
Capability              Native owner
======================  =========================================
Container and scopes    InterMix
Routing and responses   Webrick
Host/runtime lifecycle  Runwire
Database                DBLayer
Caching                 CacheLayer
Validation              ReqShield
Messaging and queues    Omnibus
Filesystem              Pathwise / Flysystem
Cryptographic engines   Epicrypt
Email / HTTP / gRPC     TalkingBytes
MFA / OTP factors       OTP
======================  =========================================

For example, Foundation adds application-specific configuration and HTTP
failure mapping around ReqShield; validation mechanics remain ReqShield's
responsibility. Database connections and queries remain DBLayer-owned.
Do not assume the framework provides an ORM or a template engine.

Foundation 4 has four explicit application graphs: web, CLI, worker and
scheduler. The HTTP server and its host are separate choices. An installed
Runwire dependency does not automatically start a long-running runtime.

The maintained implementation-level architecture is available through
:doc:`../reference/technical-guides`.
