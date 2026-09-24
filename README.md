<div align="center">

# Maatify Slug

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![Status](https://img.shields.io/badge/Status-Development-orange.svg)](#publication-state)
[![PHP](https://img.shields.io/badge/PHP-^8.4-777bb4.svg?logo=php&logoColor=white)](#requirements)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Max-brightgreen.svg)](#quality-status)

[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Usage-Guide-blue.svg)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Examples-View-blue.svg)](examples/)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](SLUG_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Contributing-Guide-blue.svg)](CONTRIBUTING.md)

An independent PHP slug lifecycle engine with clear boundaries between the slug domain, the Host, URLs, HTTP, and SEO.

</div>

---

## Publication State

The package is **Development / Unpublished**. No Stable release or SemVer Release Candidate is currently published through a public distribution channel, so there is no public registry installation command yet.

The current public contract is owned by [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). Verification evidence is SHA-scoped; historical evidence is identified below, while active integration and verification state is maintained in GitHub PR and CI history rather than duplicated in this consumer-facing README.

## Key Features

- **Slug Generation & Canonicalization:** Consistent generation and canonicalization through versioned built-in profiles.
- **Ownership & Lifecycle:** Exact claiming, generated allocation, release, scope transition, atomic transfer, and adoption.
- **Aliases & History:** Active and retired aliases with immutable lifecycle history.
- **Persistence & Concurrency:** PDO MySQL-compatible persistence with transactions, CAS, and concurrency guarantees.
- **Resolution & Management:** Resolution, availability, and management reads with shared pagination.

## Requirements

| Requirement | Value |
|---|---|
| PHP | `^8.4` |
| Database | PDO MySQL; `mysql:8.0.36` is the CI reproducibility target |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Direct packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |

## Installation

The package is not published yet. Do not present `composer require maatify/php-slug` as a currently usable public installation command. For a development checkout and local verification, see [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Quick Usage

The stateless path does not require a database or Host framework:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$slug = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
// $slug->slug->value === 'hello-world'
```

For the persisted path, the Host injects a `PDO` connection, a `ReservedSlugPolicyInterface`, and a `ClockInterface` into `SlugEngineFactory::create(...)`. See [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) for the complete workflow.

## Public Runtime API

The public Runtime provides actual paths for:

- canonicalization through `SlugTextServiceInterface`, `generateFromSource`, `canonicalizeClaim`, and `canonicalizeLookup`;
- lifecycle ownership through `SlugEngine` and `SlugLifecycleServiceInterface`, including `assignExact`;
- consumer reads through `checkAvailability`, `getCurrent`, and `resolve`; and
- management reads through `SlugManagementQueryInterface`.

This overview does not replace the complete inventory and contracts in [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## Examples

- [`examples/canonicalization.php`](examples/canonicalization.php): a stateless generation and canonicalization example.
- [`examples/persisted-lifecycle.php`](examples/persisted-lifecycle.php): a persisted MySQL lifecycle example using the canonical Compose flow.

The first example runs without Docker. The second requires the Integration environment provided by [`tools/ci/run-gate.sh`](tools/ci/run-gate.sh).

## Documentation

- [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) — consumer usage and integration.
- [`examples/`](examples/) — runnable consumer examples.
- [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) — the current public contract and Public API reference.
- [`CHANGELOG.md`](CHANGELOG.md) — change history and publication state.
- [`SECURITY.md`](SECURITY.md) — security policy and reporting route.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — development setup and verification commands.
- [`README_AR.md`](README_AR.md) — non-canonical Arabic translation of this README.

## Transactions and Concurrency

On the persisted path, the package owns the transaction when the Host has no outer transaction and uses a savepoint when participating in an outer transaction where the capability is supported. Exact claims rely on the unique Registry constraint as the final race authority and use lifecycle mutation CAS and idempotency according to the contract. See [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) for the normative details.

## Security and Trust Boundaries

The Host owns the connection, PDO configuration, entity existence, routing, HTTP, SEO, and authorization. The package does not create hidden connections or use Host foreign keys or joins. For vulnerability reports, see [Security Policy](SECURITY.md); do not disclose private vulnerability details through GitHub Issues.

## Quality Status

Historical GitHub Actions run #9 on SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b` provides evidence for that SHA only. Historical `VG-001` verification passed on exact SHA `ff72d2e00a48eb5fbd55d81dea753a7f106187ef` through Actions run `35444700308`, followed by a passing Direct Lead Fresh Full Acceptance Review before integration. These historical facts qualify only their stated SHAs and do not qualify later commits.

Verification evidence is SHA-scoped and does not qualify later commits. Current integration and verification state is maintained in GitHub PR and CI history and is intentionally not duplicated here. No current release-readiness claim is made.

## Development and Testing

For repository setup, tests, and example gates, see [`CONTRIBUTING.md`](CONTRIBUTING.md). The persisted example requires the canonical Compose lifecycle and disposable MySQL; do not use an external MySQL instance.

## License

Licensed under the [MIT License](LICENSE).

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
