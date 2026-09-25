# Slug Ordered MySQL-Compatible Package Schema

`001_slug_rc1.sql` is the published RC1 base schema. `002_operational_reporting_indexes.sql`
is the additive operational-reporting index asset published with v1.0.0-rc.2. The
published RC1 artifact does not contain `002`; it is applied when moving an existing
RC1 installation to the RC2 Runtime. The Host owns
the injected `PDO` connection and is responsible for selecting a
MySQL-compatible server that satisfies the capability contract in the
[Package Reference](../../SLUG_PACKAGE_REFERENCE.md#4-runtime-and-platform-contract).
The package does not create a connection, inspect a product/version
string, or choose a vendor-specific schema variant.

The schema uses direct PDO-compatible InnoDB semantics, `utf8mb4_bin` for
exact-safe text, and `ascii_bin` for closed ASCII tokens and keys. Enum,
status, role, event, pointer, and cross-field invariants remain package-owned;
the schema intentionally does not require generated columns, `CHECK`
enforcement, or native JSON functions.

For a fresh current installation, apply ordered assets `001_*.sql`, then
`002_*.sql`, then any future ordered assets through the Host's
migration/bootstrap process before using persistence primitives. For an existing
published RC1 installation, keep the applied `001` base and apply
`002_operational_reporting_indexes.sql` before current Runtime schema verification.
Installation order is encoded by the asset names: scopes, bindings, operations,
operation participants, registry, then history, followed by additive indexes. All
foreign keys are package-local and no Host table is joined or referenced.

The schema assets are deliberately not vendor-specific migrations and must not be
replaced with a MariaDB or MySQL variant. CI database versions are
verification targets only; runtime acceptance is capability-based.

## Canonical integration infrastructure

Integration, System, complete-suite, and Consumer Harness verification use the
repository-owned canonical lifecycle:

```text
compose.integration.yml
→ tools/ci/run-integration.sh
```

The lifecycle starts the pinned `mysql:8.0.36` image with one isolated Compose
project per run, discovers a dynamic loopback port, passes Integration-scoped
non-production credentials through the process environment, probes readiness
with PDO, and tears down the disposable state on success or failure. Developers
do not start MySQL manually or configure a database endpoint themselves.

Use these local entry points:

```bash
composer test:integration
bash tools/ci/run-gate.sh test-system
composer test
bash tools/ci/run-gate.sh consumer
```

CI and the Consumer Verification Harness consume the same Compose definition and
orchestration. The PHP runner remains on the host or CI runner; no Host database,
persistent developer state, or production credential is used.
