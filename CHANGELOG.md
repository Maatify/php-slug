# Changelog

All notable changes to `maatify/php-slug` are documented here.

## [Unreleased]

### Implemented (RC1 Foundation)

- **Package Foundation:** Built-in profiles, exact claiming, generated allocation, and public API interfaces (`SlugTextServiceInterface`, `SlugProfileInterface`, `SlugLifecycleServiceInterface`, etc.).
- **Persistence & Schema:** PDO MySQL persistence, atomic transactions, CAS logic, result snapshots, and exception propagation.
- **Lifecycle & History:** Alias creation, scoped ownership, release, purge, scope transition, atomic transfer, and legacy adoption rules.
- **Resolution & Management:** Management queries with robust shared pagination (`maatify/persistence`).
- **Concurrency & Integration:** Lock ordering and safe concurrency behavior verified via integration tests.
- **Historical Infrastructure Evidence:** GitHub Actions run #9 on SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b` reported successful Unit, Integration, system-level, and real-MySQL CI verification for that historical SHA only.
- **Historical Verification Harness:** Consumer Verification Harness was successfully reported on run #9 from clean states for SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b`; the current remediation HEAD still awaits `VG-001`.

### Documentation

- Added and updated the root `SLUG_PACKAGE_REFERENCE.md` to reflect the actual implemented public API and package boundaries.
- Created and updated `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, and `CODE_OF_CONDUCT.md` to establish accurate baseline boundaries and requirements.

### Scope Notice

- Note: Although fully implemented internally, no Tag, Release, Packagist publication, or Stable support claim has been published yet.
- The historical run above does not qualify the current remediation HEAD; `VG-001` and the Fresh Full Acceptance Review remain required.
