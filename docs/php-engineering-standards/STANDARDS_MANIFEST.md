# Maatify/php-slug — Standards Adoption Resolver Record

## Adoption Result

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Old Adoption Commit:** `3de026f86f68c548c8eef1570a6aac0e3949f20a`
- **Adoption Commit:** `593e8d1e921d6d5fa2765e9671d3d4acc49c2fc8`
- **Adoption Date:** `2026-09-22`
- **Overall Resolution Status:** `VALID`
- **Resolution Status Priority:** `INVALID > OWNER DECISION REQUIRED > VALID`
- **Exception State:** `NONE`
- **Repository:** `Maatify/php-slug`
- **Composer Package:** `maatify/php-slug`
- **Namespace:** `Maatify\\Slug\\`
- **Resolution Input:** exact upstream commit only; upstream `main` was verified at the expected SHA before resolution.

This Manifest records the completed Selective Pinned Adoption Upgrade. It is a local resolver record, not an engineering Standard and not evidence of test, CI, release, or publication success.

## Artifact Facts Used for Resolution

| Fact | Current repository evidence |
|---|---|
| Artifact type | Standalone reusable PHP/Composer library; `composer.json` declares `type: library` and production PSR-4 autoload |
| Package identity | `maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| PHP contract | `^8.4` in `composer.json` |
| Runtime extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Runtime layout | `src/`, `tests/`, `schema/mysql/`, `.github/workflows/ci.yml`, `docs/guides/USAGE_GUIDE.md`, and root `examples/` exist |
| Persistence ownership | The package owns SQL persistence behavior, package-local `maa_slug_*` schema, direct PDO repositories, schema installation, and persistence/system verification |
| Database contract | MySQL-compatible `pdo_mysql` path with direct PDO; package-owned tables and package-local joins only |
| Host boundary | Host injects PDO and collaborators; the package does not create hidden connections or depend on Host tables |
| Governance scope | `repository-governance` is activated at `/`; the repository owns durable documentation, decision, and workflow governance surfaces |
| Project-host applicability | Not applicable; this is a package-only repository, not a deployable Host/Application Project |

## Profile Activations

| Profile ID | Old Version | New Version | Scope | Extends | Activation Resolution |
|---|---:|---:|---|---|---|
| `composer-package` | `2.0.1` | `3.0.0` | `/` | `None` | `VALID` |
| `repository-governance` | `2.0.1` | `3.0.0` | `/` | `None` | `VALID` |

### Inheritance Result

No inherited Profiles. Both active Profiles declare `Extends: None`; no inheritance cycle exists.

## Stage 1 — Structural / Transitive Resolution

The exact target contains both active Profile manifests and all Required Standard references. Structural resolution produced **12 Candidate Standard References** before deduplication and **11 unique Standard IDs**:

| # | Source Profile | Candidate Standard ID | Upstream path | Structural result |
|---:|---|---|---|---|
| 1 | `composer-package` | `std-package-building` | `standards/packages/PACKAGE_BUILDING_STANDARD.md` | Present and structurally valid |
| 2 | `composer-package` | `std-composer-package` | `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | Present and structurally valid |
| 3 | `composer-package` | `std-ci-workflow` | `standards/packages/CI_WORKFLOW_STANDARD.md` | Present and structurally valid |
| 4 | `composer-package` | `std-library-presentation` | `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | Present and structurally valid |
| 5 | `composer-package` | `std-testing` | `standards/testing/TESTING_STANDARD.md` | Present and structurally valid |
| 6 | `composer-package` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Present and structurally valid |
| 7 | `composer-package` | `std-php-source-documentation` | `standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md` | Present and structurally valid |
| 8 | `composer-package` | `std-php-coding-style` | `standards/php/PHP_CODING_STYLE_STANDARD.md` | Present and structurally valid |
| 9 | `repository-governance` | `std-ai-collaboration-workflow` | `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | Present and structurally valid |
| 10 | `repository-governance` | `std-github-phase-stack-workflow` | `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | Present and structurally valid |
| 11 | `repository-governance` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Present and structurally valid; duplicate candidate |
| 12 | `repository-governance` | `std-decision-governance` | `standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md` | Present and structurally valid |

No Explicit Additional Standards were declared. No missing Profile, inheritance cycle, broken Required Standard reference, or mandatory structural metadata failure was found.

## Stage 2 — Canonical Standard Applicability

The target Standards' own applicability rules were evaluated against the current artifact facts and each active Scope:

- `std-package-building` applies to the standalone PHP/Composer library; its SQL/PDO/schema and persistence conditions apply because the package owns SQL persistence.
- `std-composer-package` applies to the standalone reusable Composer library.
- `std-ci-workflow` applies to the package verification and CI boundary.
- `std-library-presentation` applies to the standalone package and its package-owned repository presentation, including the artifact-local AI consumer discovery contract.
- `std-testing` applies to the standalone package, including its runtime, persistence, integration, system, and consumer-verification obligations where applicable.
- `std-documentation-lifecycle` applies to the standalone package and the repository-governance Scope holding current, historical, verification, release, and adoption documentation.
- `std-php-source-documentation` applies to repository-owned manually maintained PHP source in the package Scope.
- `std-php-coding-style` applies to repository-owned manually maintained PHP source in the package Scope.
- `std-ai-collaboration-workflow` applies to the `repository-governance` activation at `/`.
- `std-github-phase-stack-workflow` applies to the repository's active Phase Stack workflow at `/`.
- `std-decision-governance` applies to the repository-governance Scope because the repository can create, apply, review, and supersede durable engineering decisions; its required index is a post-adoption compliance contract, not an applicability prerequisite.

No Candidate Standard was deterministically excluded. The deduplicated Final Resolved Applicable Standards Set therefore contains **11 Standards**.

## Local Reference Closure

- **Stage 1 reference integrity:** `PASS`.
- **Final local reference closure:** `PASS` for normative inter-Standard relative references. Every such reference in the selected Control Set or Final Set resolves to another selected Control Set or Final Set artifact at the exact target. No `Reference Support Set` was created, and no non-applicable Standard was copied to keep a link alive.
- External package/reference URLs and artifact-facing links are not local Standard dependencies.

## Frozen-Version / Baseline Validation

- Existing frozen Profile baseline: `composer-package@2.0.1` and `repository-governance@2.0.1`, adopted at `3de026f86f68c548c8eef1570a6aac0e3949f20a`.
- New Profile versions are `3.0.0` for both activations; they are new major composition versions and are not presented as the old frozen versions.
- The unchanged-version Standards `std-testing@1.1.1` and `std-github-phase-stack-workflow@3.0.1` match their previous local artifacts byte-for-byte.
- All other changed Standard versions carry the target metadata from the exact target commit; no known frozen-version/content mismatch was found.
- **Baseline validation:** `PASS`.

## Pinned Adoption Control Set

All files below are pinned from the same exact upstream Adoption Commit `593e8d1e921d6d5fa2765e9671d3d4acc49c2fc8`:

- `docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md` — `std-standards-adoption@4.0.0`
- `docs/php-engineering-standards/standards/profiles/COMPOSER_PACKAGE_PROFILE.md` — `composer-package Profile@3.0.0`
- `docs/php-engineering-standards/standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md` — `repository-governance Profile@3.0.0`

No unused or non-inherited Profile manifests are copied.

## Final Resolved Applicable Standards Set

| Standard ID | Old Version | New Version | Local pinned path | Applicability result |
|---|---:|---:|---|---|
| `std-package-building` | `3.0.0` | `3.0.1` | `docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md` | Applicable to standalone PHP/Composer package; SQL persistence conditions apply |
| `std-composer-package` | `3.0.1` | `4.0.0` | `docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md` | Applicable to standalone reusable Composer package |
| `std-ci-workflow` | `2.0.0` | `3.0.0` | `docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md` | Applicable to package CI and required verification gates |
| `std-library-presentation` | `2.0.1` | `3.0.0` | `docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | Applicable to standalone package presentation and consumer discovery |
| `std-testing` | `1.1.1` | `1.1.1` | `docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md` | Applicable to package behavior and applicable integration/consumer boundaries |
| `std-documentation-lifecycle` | `2.0.0` | `3.0.0` | `docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | Applicable to package and repository-governance documentation |
| `std-php-source-documentation` | `—` | `1.0.0` | `docs/php-engineering-standards/standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md` | Applicable to repository-owned manually maintained PHP source |
| `std-php-coding-style` | `—` | `1.0.1` | `docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md` | Applicable to repository-owned manually maintained PHP source |
| `std-ai-collaboration-workflow` | `7.0.1` | `7.1.0` | `docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | Applicable to repository governance at `/` |
| `std-github-phase-stack-workflow` | `3.0.1` | `3.0.1` | `docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | Applicable to the active Phase Stack workflow |
| `std-decision-governance` | `—` | `1.0.0` | `docs/php-engineering-standards/standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md` | Applicable to durable engineering decision governance at `/` |

## Explicit Additional Standards

`None`.

## Explicit Exceptions / Overrides

`None`. No requested, pending, or unauthorized deviation was applied.

## Exact Pinned Provenance

- Every upstream-pinned file in the Control Set and Final Set was copied from `Maatify/php-engineering-standards@593e8d1e921d6d5fa2765e9671d3d4acc49c2fc8`.
- No floating `main` reference is used.
- No full upstream `standards/` snapshot, unused Profile, draft, decision, module Standard, project Standard, HTTP Standard, or versioning policy was copied.
- `AGENTS.md` was not copied from upstream.

## Resolution Boundaries

- Stage 1 completed before Stage 2; applicability was not used to hide a structural failure.
- The final set was built from the 12 actual target Profile references, deduplicated to 11 applicable Standards.
- Local reference closure was evaluated after the Final Set was formed and before pinning; no support-only copy was used.
- Profile activation and Scope diagnostics were individually `VALID`.
- Overall Resolution Status is `VALID`; Exception State is `NONE`.
- This Manifest records resolution only; it does not claim tests, CI, release, tag, publish, or merge success.
