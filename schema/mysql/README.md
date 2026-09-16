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

## Local integration database

Integration tests require a local `.env.test` file and fail when required configuration is missing. Copy the safe example, then use a dedicated local database whose name ends with `_test`:

```sh
cp .env.test.example .env.test
```

Set `SLUG_TEST_DB_HOST`, `SLUG_TEST_DB_PORT`, `SLUG_TEST_DB_NAME`, `SLUG_TEST_DB_USER`, and `SLUG_TEST_DB_PASSWORD` in `.env.test`. The test bootstrap builds the PDO DSN from these values; it does not accept an opaque DSN or production/staging database.

For a disposable MySQL verification target, the following Docker setup matches the example port and credentials:

```sh
docker run --rm --name php-slug-wu03-mysql \
  -e MYSQL_ROOT_PASSWORD=slug_root_password \
  -e MYSQL_DATABASE=maatify_slug_test \
  -e MYSQL_USER=slug_test \
  -e MYSQL_PASSWORD=slug_test_password \
  -p 3307:3306 mysql:8.0
```

After the server is ready, run the required real-database suite:

```sh
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Integration --do-not-cache-result
```

Each integration test installs and cleans the six package tables; no host tables or credentials are committed.
