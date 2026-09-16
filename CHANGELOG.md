# Changelog

All notable changes to `maatify/php-slug` are documented here.

## [Unreleased]

### Implemented (RC1 Foundation)

- **Package Foundation:** Built-in profiles, exact claiming, generated allocation, and public API interfaces (`SlugTextServiceInterface`, `SlugProfileInterface`, `SlugLifecycleServiceInterface`, etc.).
- **Persistence & Schema:** PDO MySQL persistence, atomic transactions, CAS logic, result snapshots, and exception propagation.
- **Lifecycle & History:** Alias creation, scoped ownership, release, purge, scope transition, atomic transfer, and legacy adoption rules.
- **Resolution & Management:** Management queries with robust shared pagination (`maatify/persistence`).
- **Concurrency & Integration:** Lock ordering and safe concurrency behavior verified via integration tests.
- **Infrastructure Evidence:** Fully integrated CI gates with Unit, Integration, and system-level tests running against a real MySQL environment.
- **Verification Harness:** Successfully ran Consumer Verification Harness from clean states to prove correct structural integration.

### Documentation

- Added and updated the root `SLUG_PACKAGE_REFERENCE.md` to reflect the actual implemented public API and package boundaries.
- Created and updated `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, and `CODE_OF_CONDUCT.md` to establish accurate baseline boundaries and requirements.

### Scope Notice

- Note: Although fully implemented internally, no Tag, Release, Packagist publication, or Stable support claim has been published yet.
