# Contributing to maatify/php-slug

Welcome to the Maatify Slug package. Thank you for your interest in contributing to the project!

## Package Identity and Boundaries

This is a standalone, reusable PHP/Composer library that provides a deterministic Slug Lifecycle Engine. It manages slug generation, exact claiming, generated allocation, resolution, and history persistence over MySQL, but it intentionally does *not* manage:

- HTTP Request/Response handling.
- URL Routing.
- SEO logic (e.g. 301 redirects, canonical meta tags).

Any contribution must strictly respect these boundaries. Architectural changes should be discussed before implementation.

## Types of Contributions

1. **Bug Fixes:** Please provide a reproducible test case.
2. **Feature Changes:** Architectural changes and large additions should be discussed before implementation to ensure alignment with the current canonical package contract and applicable architecture and Standards, without creating or duplicating a competing authority.
3. **Documentation:** Typo fixes and conceptual clarifications are welcome.
4. **Security Vulnerabilities:** Do not use GitHub Issues or PRs. Refer to [SECURITY.md](SECURITY.md) to report security flaws privately via `support@maatify.dev`.

## PR and Architecture Expectations

- Tests must be included for bug fixes and new features.
- Ensure that integration tests execute against the MySQL environment.
- Do not commit changes to `composer.lock`; this is a reusable library, and `composer.lock` is ignored.

## Prerequisites for Local Development

- **PHP:** `^8.4`
- **Extensions:** `intl`, `mbstring`, `pdo`, `pdo_mysql`
- **Docker Engine:** Required for Integration, System, complete-suite, Consumer Harness, and examples-smoke gates.
- **Docker Compose v2:** Required for the repository-owned Integration lifecycle.

The PHP runtime remains on the host or CI runner. The canonical disposable database uses
`mysql:8.0.36`, binds a dynamic port on loopback, and uses the temporary database
`maatify_slug_test` with disposable non-production credentials. The orchestration starts the
isolated Compose service, discovers the port, exports the Integration-scoped process environment,
runs the requested verification, and tears everything down. Developers must not provide an
external MySQL service or export `SLUG_TEST_DB_*` variables manually.

## Running Tests and Quality Gates

Before submitting a Pull Request, ensure that all quality gates pass locally. The single source of truth for running gates is the `tools/ci/run-gate.sh` script.

### 1. Static Analysis & Coding Standards

```bash
bash tools/ci/run-gate.sh latest-quality
```
This runs PHPStan and PHP-CS-Fixer.

Note: In CI, this quality gate is exercised on both PHP 8.4 and PHP 8.5 to ensure compatibility.

### 2. Run Test Suites

Run Unit tests only; Docker is not required:
```bash
composer test:unit
```

Run the focused Integration suite through the canonical Compose lifecycle:
```bash
composer test:integration
```

Run focused System evidence through the same lifecycle:
```bash
bash tools/ci/run-gate.sh test-system
```

Run the complete maintained suite (Unit + Integration + System):
```bash
composer test
```

Resolve latest dependencies and run the complete suite:
```bash
bash tools/ci/run-gate.sh latest-tests
```

Resolve lowest dependencies and run the complete suite:
```bash
bash tools/ci/run-gate.sh lowest-tests
```

### 3. Verification Harness

Verify consumer integration by running the consumer harness from clean external Composer roots:
```bash
bash tools/ci/run-gate.sh consumer
```

The Consumer Verification Harness uses the same canonical Compose definition and lifecycle as
the Integration and System suites.

### 4. Examples smoke verification

Run all maintained standalone examples, with persisted examples using the canonical Compose lifecycle:
```bash
bash tools/ci/run-gate.sh examples-smoke
```

The persisted example uses the same `compose.integration.yml`, isolated project, dynamic loopback
port, readiness probe, process environment, diagnostics, and teardown as the other Integration
boundaries.

### 5. Workflow Linting and Diff Checks

Lint GitHub workflows:
```bash
bash tools/ci/run-gate.sh workflow-lint
```
Check for trailing whitespace or formatting errors introduced in your PR:
```bash
bash tools/ci/run-gate.sh whitespace BASE...HEAD
```
Replace `BASE...HEAD` with your specific base and head branches (e.g., `main...HEAD`).

Thank you for contributing to the stability and reliability of the Maatify Ecosystem!
