# Changelog

All notable changes to `maatify/php-slug` are documented here.
The format follows Keep a Changelog conventions and Semantic Versioning.

## [Unreleased]

## [1.0.0-rc.1] - 2026-09-24

### Added

- **Package Foundation:** Built-in profiles, exact claiming, generated allocation, and public API interfaces (`SlugTextServiceInterface`, `SlugProfileInterface`, `SlugLifecycleServiceInterface`, etc.).
- **Persistence & Schema:** PDO MySQL persistence, atomic transactions, CAS logic, result snapshots, and exception propagation.
- **Lifecycle & History:** Alias creation, scoped ownership, release, purge, scope transition, atomic transfer, and legacy adoption rules.
- **Resolution & Management:** Management queries with robust shared pagination (`maatify/persistence`).
- **Concurrency & Integration:** Lock ordering and safe concurrency behavior verified via integration tests.
- **Historical Infrastructure Evidence:** GitHub Actions run #9 on SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b` reported successful Unit, Integration, system-level, and real-MySQL CI verification for that historical SHA only.
- **Historical Verification Harness:** Consumer Verification Harness was successfully reported on run #9 from clean states for SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b`; that evidence applies to the historical SHA only.
- **Historical VG-001:** Verification passed on exact SHA `ff72d2e00a48eb5fbd55d81dea753a7f106187ef` through Actions run `35444700308`, followed by a passing Direct Lead Fresh Full Acceptance Review before integration. This historical evidence qualifies only the exact SHA stated and does not qualify later commits.
- Added and updated the root `SLUG_PACKAGE_REFERENCE.md` to reflect the actual implemented public API and package boundaries.
- Created and updated `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, and `CODE_OF_CONDUCT.md` to establish accurate baseline boundaries and requirements.

### Changed

- **Release Lifecycle:** `v1.0.0-rc.1` is the first SemVer Release Candidate. It is Pre-Stable and does not create a Stable support line.
- **Publication Truth:** A CHANGELOG entry alone is not publication proof; Published state is determined by external Composer resolvability of the matching version through the approved distribution source.

[Unreleased]: https://github.com/Maatify/php-slug/compare/v1.0.0-rc.1...HEAD
[1.0.0-rc.1]: https://github.com/Maatify/php-slug/releases/tag/v1.0.0-rc.1
