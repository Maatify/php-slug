# DEC-001 — Built-in Profile Runtime Compatibility

| Field | Value |
|---|---|
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Built-in slug profile runtime compatibility |

## Decision

`ascii-v1` and `unicode-v1` fix their profile algorithms and observable contracts, not the ICU version installed by the operating system. Built-in profiles use the runtime capabilities declared by `ext-intl`.

There is no exact ICU or Unicode-data version pin on the consumer. Runtime admission is based on availability and the required behavior probes. Exact ICU and Unicode versions may be exposed as diagnostic evidence only and are not support boundaries.

Missing or incompatible normalization or transliteration capability fails closed with `SlugRuntimeCompatibilityException`. The current profile algorithms do not change in this remediation:

- `unicode-v1`: NFC → `Any-Lower` → current Unicode canonical rules.
- `ascii-v1`: NFC → `Any-Latin; Latin-ASCII` → `Any-Lower` → current ASCII canonical rules.

Future profile semantic changes must not happen silently under the same versioned key. An incompatible semantic change requires an independent decision and may require a new profile version. This decision requires no new runtime dependency.
