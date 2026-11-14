# Infbyte documentation

The developer manual is [Infbyte — PHP Application Framework](index.rst), built
with Sphinx in the **Foundation** repository. Its paths `config/`, `routes/`,
`app/`, `bootstrap/` and `storage/` refer to **the Infbyte application
checkout**, not the Foundation package. The package's
`resources/config/` contains internal templates.

**Version:** This manual documents the unpublished Foundation/Infbyte 4
development candidate. Infbyte `main` still requires Foundation 3.
[Candidate setup](start/foundation4-candidate.rst) and
[publication gates](reference/publication-readiness.rst) explain the difference;
do not assume `composer create-project` currently installs version 4.

## Build and check

```bash
python -m pip install -r docs/requirements.txt
python -m sphinx -b html -n -W --keep-going docs docs/_build/html
python docs/tools/check_public_contracts.py
python docs/tools/lint_php_blocks.py
```

The Sphinx manual includes practical HTTP, database, auth, queue, testing,
runtime and deployment tutorials, together with the
[CLI](reference/cli.rst), [configuration](reference/configuration.rst) and
[API](reference/application-api.rst) references.

## Retained Foundation technical references

Deep internal contracts and release/operational evidence are retained as
source documentation, separate from the application-facing manual:

- [Foundation 4 setup and migration](foundation-4-migration.md)
- [Foundation 4 release notes](foundation-4.0-release-notes.md)
- [Retained-state rehearsal](foundation-4-retained-data-rehearsal.md)
- [Native-host performance certification](foundation-4-host-benchmark-certification.md)
- [Optional feature internals](modules.md)
- [OAuth 2.1/OIDC/PAT internals](oauth-2.1.md)
- [OTP and passkey internals](otp.md)
- [Runtime hosting internals](runtime-hosting.md)
- [Operations internals](operations.md)
- [Foundation ownership boundaries](architecture/ownership-boundaries.md)

The primary implementation and release gates are independently verified in
[Security & Standards](../.github/workflows/security-standards.yml). Hosted
Read the Docs publication, stable package cutover and external link health
are not certified by a local Sphinx build.
