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
2. **Feature Changes:** Architectural changes and large additions should be discussed before implementation to ensure alignment with the package's design blueprint.
3. **Documentation:** Typo fixes and conceptual clarifications are welcome.
4. **Security Vulnerabilities:** Do not use GitHub Issues or PRs. Refer to [SECURITY.md](SECURITY.md) to report security flaws privately via `support@maatify.com`.

## PR and Architecture Expectations

- Tests must be included for bug fixes and new features.
- Ensure that integration tests execute against the MySQL environment.
- Do not commit changes to `composer.lock`; this is a reusable library, and `composer.lock` is ignored.

## Prerequisites for Local Development

- **PHP:** `^8.4`
- **Extensions:** `intl`, `mbstring`, `pdo`, `pdo_mysql`
- **Database:** A MySQL database. The CI verification target is `8.0.36`, but the package uses standard MySQL-compatible semantics.

For integration DB gates, the user/CI environment supplies these environment variables:
- `SLUG_TEST_DB_HOST`
- `SLUG_TEST_DB_PORT`
- `SLUG_TEST_DB_NAME`
- `SLUG_TEST_DB_USER`
- `SLUG_TEST_DB_PASSWORD`

`tools/ci/run-gate.sh` / `integration-env` then writes the ignored local `.env.test` using these variables.

## Running Tests and Quality Gates

Before submitting a Pull Request, ensure that all quality gates pass locally. The single source of truth for running gates is the `tools/ci/run-gate.sh` script.

### 1. Static Analysis & Coding Standards

```bash
bash tools/ci/run-gate.sh latest-quality
```
This runs PHPStan and PHP-CS-Fixer.

Note: In CI, this quality gate is exercised on both PHP 8.4 and PHP 8.5 to ensure compatibility.

### 2. Run Test Suites

Run the tests against the latest dependencies:
```bash
bash tools/ci/run-gate.sh latest-tests
```
Run the tests against the lowest dependencies:
```bash
bash tools/ci/run-gate.sh lowest-tests
```

**Note:** Test gates require a clean MySQL testing database. As documented above, PHPUnit consumes the ignored `.env.test` file generated dynamically by `integration-env` / composite DB gates based on the `SLUG_TEST_DB_*` environment variables you export.

### 3. Verification Harness

Verify consumer integration by running the consumer harness from clean external Composer roots:
```bash
bash tools/ci/run-gate.sh consumer
```

### 4. Workflow Linting and Diff Checks

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
