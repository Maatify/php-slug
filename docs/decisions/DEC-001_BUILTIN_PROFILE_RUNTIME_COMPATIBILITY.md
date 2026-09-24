# DEC-001 — Built-in Profile Runtime Compatibility

| Field | Value |
|---|---|
| Decision ID | `DEC-001` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Built-in slug profile runtime compatibility |
| Date | `2026-09-24` |
| Repository Owner | Mohamed Abdulalim |
| Technical Decision Owner | Direct Lead |

## Context

The package declares PHP `^8.4` and `ext-intl`. External Consumer Qualification successfully installed the package through Composer on PHP `8.5.9`, ICU `78.3`, and Unicode `17.0.0.0`.

The previous runtime admission rejected built-in profiles unless the host provided exactly ICU 74 and Unicode 15.1. This created host coupling that was not visible in Composer requirements: installation succeeded, then the first supported built-in Public API path failed. The package is reusable and Host-agnostic, so an exact operating-system ICU pin is not an appropriate consumer contract when the required behavior is available.

## Decision

`ascii-v1` and `unicode-v1` fix their profile algorithms and observable contracts, not the ICU version installed by the operating system. Built-in profiles use the runtime capabilities declared by `ext-intl`.

There is no exact ICU or Unicode-data version pin on the consumer. Runtime admission is based on availability and the required behavior probes. Exact ICU and Unicode versions may be exposed as diagnostic evidence only and are not support boundaries.

Missing or incompatible normalization or transliteration capability fails closed with `SlugRuntimeCompatibilityException`. The current profile algorithms do not change in this remediation:

- `unicode-v1`: NFC → `Any-Lower` → current Unicode canonical rules.
- `ascii-v1`: NFC → `Any-Latin; Latin-ASCII` → `Any-Lower` → current ASCII canonical rules.

Future profile semantic changes must not happen silently under the same versioned key. An incompatible semantic change requires an independent decision and may require a new profile version. This decision requires no new runtime dependency.

## Rationale

1. An exact ICU version cannot be represented as a precise Composer platform contract for consumers.
2. An ICU version is a broader proxy than the actual requirement: the required normalization and transliteration behavior is what matters.
3. Capability and observable-behavior verification avoids rejecting newer hosts that behave correctly.
4. Preserving the existing algorithms and locked behavior prevents a portability fix from becoming a canonicalization redesign.
5. An incompatible future profile semantic change must not be introduced silently under the same versioned key.

## Consequences

- Hosts with newer ICU versions are not rejected solely because of the version number.
- Runtime admission remains fail-closed when required capabilities are missing or incompatible.
- Compatibility verification runs once per process after success through the current success cache.
- CI provides independent runtime-diversity evidence.
- ICU and Unicode versions remain diagnostic evidence only.
- No migrations or schema changes are required.
- No new Composer runtime dependency is required.
- There is no API change and no change to the `ascii-v1` or `unicode-v1` algorithms.

## Canonical Contract / Current Owner

The current normative consumer contract is:

- `SLUG_PACKAGE_REFERENCE.md` §4 — Runtime and Platform Contract for RC1
- `SLUG_PACKAGE_REFERENCE.md` §5 — Identity and Text

`SLUG_PACKAGE_REFERENCE.md` owns the current consumer contract; this decision record preserves the decision, rationale, and governance history.
