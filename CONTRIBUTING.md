# Contributing to maatify/php-slug

Welcome to the Maatify Slug package. Thank you for your interest in contributing to the project!

## Package Identity and Boundaries

This is a standalone, reusable PHP/Composer library that provides a deterministic Slug Lifecycle Engine. It manages slug generation, exact claiming, generated allocation, resolution, and history persistence over MySQL, but it intentionally does *not* manage:

- HTTP Request/Response handling.
- URL Routing.
- SEO logic (e.g. 301 redirects, canonical meta tags).

Any contribution must strictly respect these boundaries. Architectural changes should be discussed before implementation.

## Types of Contributions

1. **Bug Fixes:** Please open an issue with a reproducible test case.
2. **Feature Changes:** Require prior discussion via issues. Do not send large PRs without an accepted proposal or design blueprint.
3. **Documentation:** Minor typo fixes are welcome. Conceptual changes require an issue.
4. **Security Vulnerabilities:** Do not use GitHub Issues or PRs. Refer to [SECURITY.md](SECURITY.md) to report security flaws privately via `support@maatify.com`.

## PR and Architecture Expectations

- Tests must be included for bug fixes and new features.
- Ensure that integration tests execute against the MySQL environment.
- Do not commit changes to `composer.lock`; this is a reusable library, and `composer.lock` is ignored.

## Prerequisites for Local Development

- **PHP:** `^8.4`
- **Extensions:** `intl`, `mbstring`, `pdo`, `pdo_mysql`
- **Database:** A MySQL database (compatible with `8.0.36`) to run the integration test suite.

For tests, a `.env.test` file is required. This file is local-only and should not be committed to the repository (an example `.env.test.example` is provided). It must define the required testing variables as evaluated by the testing harness.

## Running Tests and Quality Gates

Before submitting a Pull Request, ensure that all quality gates pass locally. The single source of truth for running gates is the `tools/ci/run-gate.sh` script.

### 1. Static Analysis & Coding Standards

```bash
bash tools/ci/run-gate.sh latest-quality
```
This runs PHPStan and PHP-CS-Fixer.

### 2. Run Test Suites

Run the tests against the latest dependencies:
```bash
bash tools/ci/run-gate.sh latest-tests
```
Run the tests against the lowest dependencies:
```bash
bash tools/ci/run-gate.sh lowest-tests
```

**Note:** Test gates require a clean MySQL testing database configured via your `.env.test`.

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
