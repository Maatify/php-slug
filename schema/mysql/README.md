# Slug RC1 MySQL-compatible schema

`001_slug_rc1.sql` is the single package-owned schema for RC1. The Host owns
the injected `PDO` connection and is responsible for selecting a
MySQL-compatible server that satisfies the capability contract in Blueprint
§11. The package does not create a connection, inspect a product/version
string, or choose a vendor-specific schema variant.

The schema uses direct PDO-compatible InnoDB semantics, `utf8mb4_bin` for
exact-safe text, and `ascii_bin` for closed ASCII tokens and keys. Enum,
status, role, event, pointer, and cross-field invariants remain package-owned;
the schema intentionally does not require generated columns, `CHECK`
enforcement, or native JSON functions.

Install the file through the Host's migration/bootstrap process before using
the persistence primitives. Installation order is already encoded in the
file: scopes, bindings, operations, operation participants, registry, then
history. All foreign keys are package-local and no Host table is joined or
referenced.

The file is deliberately not a version-specific migration and must not be
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
