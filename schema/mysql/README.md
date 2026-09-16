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
