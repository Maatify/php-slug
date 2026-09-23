# PER Coding Style 3.1 Coverage Matrix

The repository-owned `style` gate is a composite verifier:

1. PHP-CS-Fixer's explicit, non-floating `@PER-CS3x0` ruleset covers the PER-CS 3.0 baseline.
2. `tools/ci/verify-per-cs31.php` covers applicable PER-CS 3.1 obligations that the 3.0 preset does not establish.

The adopted normative baseline remains PHP-FIG PER Coding Style 3.1. `@PER-CS` and other floating selectors are intentionally not used.

| PER-CS 3.1 delta | Applicable? | Base `@PER-CS3x0` | Supplemental verification | Evidence / rationale |
| --- | --- | --- | --- | --- |
| §4.7 `clone()` parentheses | Yes | No | Yes | Token verifier rejects `clone $value`; the repository supports PHP `^8.4`, where `clone(...)` is valid syntax. This is a SHOULD in PER-CS 3.1 and is enforced by the supplemental gate. |
| §5.2 case body MUST NOT use `{}` | Yes | No | Yes | Token verifier inspects every `switch` case body. |
| §5.2 non-empty case MUST terminate | Yes | No | Yes | Token verifier requires a terminating keyword; deliberate fall-through also requires an explicit comment. |
| §5.2 intentional fall-through comment | Yes | Partial (`no_break_comment`) | Yes | The base fixer checks comment presence; the supplemental verifier checks the 3.1 termination contract and accepted intentional-fall-through wording. |
| §5.2 multi-line case condition layout | Yes | No | Yes | Token verifier requires `case (` and a final `):` layout when the case header spans lines. |
| §6.2 `|>` spacing | No | No | Guard | The package declares PHP `^8.4`; the pipe operator is not available across the supported runtime floor. The verifier rejects accidental `|>` usage so applicability cannot drift silently. |
| §6.4 multi-line `|>` placement | No | No | Guard | Same PHP `^8.4` syntax applicability decision; any occurrence fails with an explicit non-applicability message. |
| §7 empty closure `{}` on same line | Yes | Yes (`single_line_empty_body`) | No duplicate | `php-cs-fixer describe @PER-CS3x0` identifies `single_line_empty_body` in the expanded preset. No duplicate verifier is added. |
| §7 arrow-function preference | Yes | No | No | SHOULD only; no semantic rewrite is introduced. The verifier does not mutate or impose a non-normative preference. |
| §8 anonymous-class attribute placement | Yes | Partial (`attribute_block_no_spaces`) | Yes | Base fixer covers attribute token spacing; supplemental verifier covers the 3.1 `new` → attribute → `class` line ordering. |
| §9 non-public enum constants use `private` | Yes | No | Yes | Token verifier rejects `protected` declarations inside enum bodies. |
| §11 multiline array opening bracket | Yes | Partial (`array_indentation`, `array_syntax`) | Yes | Supplemental verifier rejects an opening `[` placed alone on a line; the base preset covers the remaining array formatting baseline. |

The verifier scans repository-owned manually maintained PHP under `src/`, `tests/`, `examples/`, `tools/`, and the root `.php-cs-fixer.php`. It excludes `vendor/` and generated code by construction.
