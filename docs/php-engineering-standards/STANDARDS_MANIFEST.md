# Maatify/php-slug — Standards Adoption Resolver Record

## Adoption Result

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Adoption Commit:** `3de026f86f68c548c8eef1570a6aac0e3949f20a`
- **Adoption Date:** `2026-09-20`
- **Overall Resolution Status:** `VALID`
- **Resolution Status Priority:** `INVALID > OWNER DECISION REQUIRED > VALID`
- **Exception State:** `NONE`
- **Repository:** `Maatify/php-slug`
- **Composer Package:** `maatify/php-slug`
- **Namespace:** `Maatify\\Slug\\`
- **Resolution Input:** exact upstream commit only; upstream `main` was verified at the same SHA before resolution.

This Manifest records the completed Selective Pinned Adoption Upgrade. It is a local resolver record, not an engineering Standard and not evidence of test, CI, release, or publication success.

## Artifact Facts Used for Resolution

| Fact | Current repository evidence |
|---|---|
| Artifact type | Standalone reusable PHP/Composer library; `composer.json` declares `type: library` and production PSR-4 autoload |
| Package identity | `maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| PHP contract | `^8.4` in `composer.json` |
| Runtime extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Runtime layout | `src/`, `tests/`, `schema/mysql/`, `.github/workflows/ci.yml`, `docs/guides/USAGE_GUIDE.md`, and root `examples/` exist in the current checkout |
| Persistence ownership | The package owns SQL persistence behavior, package-local `maa_slug_*` schema, direct PDO repositories, schema installation, and persistence/system verification |
| Database contract | MySQL-compatible `pdo_mysql` path with direct PDO; package-owned tables and package-local joins only |
| Host boundary | Host injects PDO and collaborators; the package does not create hidden connections or depend on Host tables |
| Governance scope | Repository governance is activated at `/` |

## Profile Activations

| Profile ID | Profile Version | Scope | Extends | Activation Resolution |
|---|---:|---|---|---|
| `composer-package` | `2.0.1` | `/` | `None` | `VALID` |
| `repository-governance` | `2.0.1` | `/` | `None` | `VALID` |

### Inheritance Result

No inherited Profiles. Both active Profiles declare `Extends: None`; no inheritance cycle exists.

## Stage 1 — Structural / Transitive Resolution

The exact target contains both active Profile manifests and all Required Standard references. Structural resolution produced nine Candidate Standard References before deduplication:

| Source Profile | Candidate Standard ID | Upstream path | Structural result |
|---|---|---|---|
| `composer-package` | `std-package-building` | `standards/packages/PACKAGE_BUILDING_STANDARD.md` | Present and structurally valid |
| `composer-package` | `std-composer-package` | `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | Present and structurally valid |
| `composer-package` | `std-ci-workflow` | `standards/packages/CI_WORKFLOW_STANDARD.md` | Present and structurally valid |
| `composer-package` | `std-library-presentation` | `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | Present and structurally valid |
| `composer-package` | `std-testing` | `standards/testing/TESTING_STANDARD.md` | Present and structurally valid |
| `composer-package` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Present and structurally valid |
| `repository-governance` | `std-ai-collaboration-workflow` | `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | Present and structurally valid |
| `repository-governance` | `std-github-phase-stack-workflow` | `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | Present and structurally valid |
| `repository-governance` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Present and structurally valid; duplicate candidate |

No Explicit Additional Standards were declared.

## Stage 2 — Canonical Standard Applicability

The target Standards' own applicability rules were evaluated against the current artifact facts and each active Scope:

- `std-package-building` applies to the standalone PHP/Composer library; its SQL/PDO/schema and persistence conditions apply because the package owns SQL persistence.
- `std-composer-package` applies to the standalone reusable Composer library.
- `std-ci-workflow` applies to the package verification and CI boundary.
- `std-library-presentation` applies to the standalone package and its package-owned repository presentation.
- `std-testing` applies to the standalone package, including its runtime, persistence, integration, system, and consumer-verification obligations where applicable.
- `std-documentation-lifecycle` applies to the standalone package and the repository-governance scope holding current, historical, verification, release, and adoption documentation.
- `std-ai-collaboration-workflow` applies to the `repository-governance` activation at `/`.
- `std-github-phase-stack-workflow` applies to the repository's active Phase Stack workflow at `/`.

No Candidate Standard was deterministically excluded. The deduplicated Final Resolved Applicable Standards Set therefore contains eight Standards.

## Pinned Adoption Control Set

All files below are pinned from the same exact upstream Adoption Commit `3de026f86f68c548c8eef1570a6aac0e3949f20a`:

- `docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md` — `std-standards-adoption@3.1.0`
- `docs/php-engineering-standards/standards/profiles/COMPOSER_PACKAGE_PROFILE.md` — `composer-package Profile@2.0.1`
- `docs/php-engineering-standards/standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md` — `repository-governance Profile@2.0.1`

No unused or non-inherited Profile manifests are copied.

## Final Resolved Applicable Standards Set

| Standard ID | Version | Local pinned path | Applicability result |
|---|---:|---|---|
| `std-package-building` | `3.0.0` | `docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md` | Applicable to standalone PHP/Composer package; SQL persistence conditions apply |
| `std-composer-package` | `3.0.1` | `docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md` | Applicable to standalone reusable Composer package |
| `std-ci-workflow` | `2.0.0` | `docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md` | Applicable to package CI and required verification gates |
| `std-library-presentation` | `2.0.1` | `docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | Applicable to standalone package presentation and repository surfaces |
| `std-testing` | `1.1.1` | `docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md` | Applicable to package behavior and applicable integration/consumer boundaries |
| `std-documentation-lifecycle` | `2.0.0` | `docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Applicable to package and repository-governance documentation |
| `std-ai-collaboration-workflow` | `7.0.1` | `docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | Applicable to repository governance at `/` |
| `std-github-phase-stack-workflow` | `3.0.1` | `docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | Applicable to the active Phase Stack workflow |

## Explicit Additional Standards

`None`.

## Explicit Exceptions / Overrides

`None`. No requested, pending, or unauthorized deviation was applied.

## Link Integrity and Adoption Provenance

- Relative links between the local Control Set and the local Final Resolved Applicable Standards Set are structurally valid.
- All pinned upstream files come from the same exact commit `3de026f86f68c548c8eef1570a6aac0e3949f20a`; no mixed adoption exists.
- References from the pinned upstream Standards to central governance, module, or project documents that are intentionally outside this selective set remain upstream references; they do not authorize copying unused files.
- `docs/audits/`, `docs/decisions/`, `STANDARD_VERSIONING_POLICY_AR.md`, unused Profiles, modules, projects, and the remainder of the upstream standards tree are not part of this Adoption.
- No extra local Profile manifests or unused Standards are included in the pinned Adoption tree.

## Resolution Boundaries

- Resolution was rebuilt from the current local Profile Activations, the current artifact facts, and exact upstream commit `3de026f86f68c548c8eef1570a6aac0e3949f20a`.
- Stage 1 completed before Stage 2; applicability was not used to hide a structural failure.
- Upstream `main` was checked at the expected SHA before pinning; no floating reference is used.
- The previous valid Manifest at Adoption Commit `4e268089d0aceedbc837d98f28da8b204d39dd7f` was replaced only after the new resolution was `VALID`.
- This Manifest records resolution only; it does not claim tests, CI, release, tag, publish, or merge success.
