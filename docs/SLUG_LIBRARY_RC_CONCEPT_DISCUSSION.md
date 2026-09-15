# Maatify Slug — RC1 Concept Discussion Draft

> **Status:** Discussion Draft  
> **Repository:** `Maatify/php-slug`  
> **Composer package:** `maatify/php-slug`  
> **Namespace:** `Maatify\Slug\`  
> **Purpose:** Define and challenge the complete architectural scope for the first Release Candidate before producing the formal Blueprint and implementation plan.  
> **Lifecycle:** Temporary preparation document. Accepted decisions must be transferred into durable planning/contract documents, then this file must be deleted before preparation closes.

---

# 1. Document Role

This file is deliberately **not** the Package Reference, Blueprint, schema specification, or final public API contract.

It exists to make the architectural idea reviewable before implementation begins.

Statements in this document fall into three categories:

- **Proposed invariant** — intended to become a locked architectural rule unless review finds a concrete defect.
- **Conceptual model** — describes the required capability without prematurely locking exact columns/classes/method names.
- **Open design decision** — must be resolved in the Blueprint before implementation.

The document must not be used to bypass the formal standards-adoption, Blueprint, implementation, verification, or release gates.

---

# 2. Standards Baseline for This Discussion

This discussion has been reviewed against the current Maatify engineering standards baseline at:

```text
Maatify/php-engineering-standards
main: 2fc57f9320f8a7f7147fb20abbcfa311fdf40c28
```

Relevant profiles/standards include, at minimum:

```text
composer-package profile
repository-governance profile
Package Building Standard
Composer Package Standard
CI Workflow Standard
Library Presentation Standard
Testing Standard
GitHub Phase Stack Workflow
Standards Adoption Standard
```

Important consequences already reflected here:

- new Maatify PHP packages use the PHP 8.4 baseline;
- the package is standalone and Host-agnostic;
- Host tables must not be joined or referenced by foreign key;
- package-owned persistence uses the package's infrastructure boundary and direct PDO where persistence applies;
- package-defined exceptions use `maatify/exceptions`;
- package time uses `Maatify\SharedCommon\Contracts\ClockInterface` where a clock is required;
- shared Maatify pagination capabilities must be reused rather than duplicated;
- persistence behavior requires real integration evidence;
- externally observable package workflows require system-level protection;
- the standalone package requires a Consumer Verification Harness.

This section is a **review baseline only**. It does not replace the repository's future pinned Standards Manifest / Adoption Control Set.

---

# 3. Objective

The package must not be a thin `slugify()` helper and must not merely recreate the slug-history feature removed from SEO.

The intended package is:

> **An authoritative, standalone Slug Lifecycle Engine for PHP applications.**

The first RC should contain the complete architectural foundation needed for professional slug use without forcing a later redesign of:

- identity,
- scope,
- normalization,
- allocation,
- ownership,
- persistence,
- lifecycle,
- aliases,
- history,
- resolution,
- concurrency,
- transactions,
- migration/adoption,
- or public domain contracts.

Future development should primarily be additive: new profiles, policies, adapters, strategies, or integrations should not require replacing the core model.

---

# 4. Core Boundary

The package owns the **slug domain**.

It does not own the complete URL, route, HTTP, SEO, framework, or Host-entity domains.

## 4.1 Owned by the package

The RC foundation should own:

- slug generation;
- slug normalization;
- slug validation;
- Unicode-aware slug behavior;
- optional ASCII/transliteration behavior;
- versioned slug profiles;
- slug scope identity;
- Host-provided entity identity;
- reserved-slug policy integration;
- advisory availability checks;
- exact claiming;
- automatic allocation;
- collision handling;
- current slug ownership;
- historical canonical ownership;
- active aliases;
- retired aliases;
- restoring historical canonical slugs;
- alias promotion/reactivation/retirement;
- current/historical/alias/retired lookup;
- canonicality reporting;
- binding activation/deactivation;
- explicit ownership release;
- explicit destructive purge when required;
- immutable normal-lifecycle history;
- legacy adoption;
- management queries;
- package-owned persistence;
- atomic transaction behavior;
- concurrency protection;
- limited Host-provided audit context;
- stable semantic result and exception contracts.

## 4.2 Explicitly outside the package

The package must not own:

- route definitions;
- application routing;
- full path construction;
- full URL generation;
- URI percent-encoding/decoding;
- HTTP controllers;
- HTTP middleware;
- HTTP status policy;
- `301`, `302`, `404`, or `410` decisions;
- SEO redirect records;
- canonical tags;
- hreflang;
- framework model hooks;
- ORM listeners;
- automatic monitoring of entity title/name changes;
- Host entity existence checks;
- Host entity persistence;
- Host table joins;
- Host foreign keys;
- authentication;
- authorization;
- Admin UI;
- SEO policy;
- application bootstrap.

Any integration between Slug and SEO, routing, HTTP, entities, or framework lifecycle belongs to the Host or a separate adapter.

There must be no direct dependency between `maatify/php-slug` and `maatify/php-seo` in either direction.

---

# 5. What a Slug Means in This Package

A persisted/public slug value represents a **decoded canonical URL path-segment token**.

Example:

```text
iphone-17-pro
آيفون-١٧-برو
```

It is not:

```text
/en/products/iphone-17-pro
https://example.com/en/products/iphone-17-pro
iphone%20pro
```

The Host/router owns URI transport concerns such as percent-decoding and percent-encoding.

The Slug package receives and returns semantic slug values, validates that they cannot violate the configured slug/profile contract, and never stores percent-encoded transport representations as canonical slug identity.

This boundary avoids mixing slug identity with URL serialization.

---

# 6. Two Legitimate Usage Modes

The same package should support two usage levels.

## 6.1 Stateless generation/normalization

A consumer may use generation and normalization without configuring package persistence.

Example:

```text
Input:
iPhone 17 Pro Max

Output:
iphone-17-pro-max
```

"Without persistence" means no database schema, connection, or lifecycle storage is required for this workflow. It does not promise that Composer will install no persistence-related runtime dependency if the single package contains persistent capabilities.

## 6.2 Authoritative persisted lifecycle

A consumer may delegate the complete slug lifecycle to the package:

```text
Generate
→ Claim
→ Current
→ Change
→ History
→ Resolve
```

A Host using persisted lifecycle should not need to build a second authoritative slug-history or ownership system beside the package.

---

# 7. Entity Identity

The package must not assume Host entity IDs are integers, UUIDs, or database keys.

Proposed value-object concept:

```text
EntityReference

entityType
entityKey
```

Examples:

```text
product / 123
product / 01JABC...
article / external-cms-448
```

`entityType` and `entityKey` are opaque, stable Host-provided identifiers.

The package must not:

- verify that the Host entity exists;
- query the Host entity table;
- join to Host data;
- add an FK to Host data;
- infer the Host storage type.

The Host is responsible for supplying a stable canonical representation consistently.

Exact length/character constraints for `entityType` and `entityKey` are Blueprint decisions.

---

# 8. Slug Scope

Slug uniqueness must not be globally hardcoded.

The package needs a first-class scope that represents the exact ownership/uniqueness boundary.

Conceptually:

```text
SlugScope

namespace
localeKey
contextKey
```

A persisted scope also carries an immutable slug-profile configuration:

```text
profileKey
```

**Important:** `profileKey` configures a scope; it is **not part of the logical uniqueness identity of the scope**.

Otherwise two rows such as:

```text
product / en / global / unicode-v1
product / en / global / unicode-v2
```

could accidentally become parallel ownership universes for the same logical route scope.

## 8.1 `namespace`

Required logical namespace.

Examples:

```text
product
category
article
username
global-content
```

## 8.2 `localeKey`

Optional Host-provided scope dimension.

Examples:

```text
ar
en
ar-EG
12
```

It is opaque to the package.

The package must not assume it is:

- a language database ID;
- a BCP-47 tag;
- the transliteration locale;
- or any specific Host representation.

## 8.3 `contextKey`

Optional additional uniqueness dimension.

Examples:

```text
tenant:5
store:2
parent:52
site:ep4n
tenant:5|parent:52
```

Possible uses include:

- category slugs unique per parent;
- content unique per tenant;
- content unique per site;
- locale-specific content;
- globally unique content when no extra context is supplied.

The package treats the value as opaque. If a Host composes multiple concepts into one `contextKey`, stable composition/escaping is the Host's responsibility.

## 8.4 Canonical empty dimensions

Persistence must not rely on nullable uniqueness semantics for missing scope dimensions.

The exact schema representation is a Blueprint decision, but "no locale" and "no context" must have one canonical identity and must not create duplicate scopes because of database `NULL` behavior.

## 8.5 Scope identity invariant

The logical persisted scope identity is:

```text
namespace + localeKey + contextKey
```

`profileKey` is immutable configuration attached to that identity.

Once a persisted scope owns claims, its profile must not silently change.

---

# 9. Versioned Slug Profiles

Slug behavior must not be controlled by one globally mutable algorithm.

The package should use named, versioned profile keys.

Examples only:

```text
unicode-v1
ascii-v1
```

A profile defines observable behavior such as:

```text
Unicode normalization form/policy
case normalization
separator policy
allowed-character policy
transliteration policy
maximum length
collision-suffix rules
input validation rules
```

## 9.1 Stability rule

A built-in versioned profile is a behavior contract.

A future algorithm change that can alter output must use a new profile key rather than silently changing existing persisted scope semantics.

## 9.2 Persisted-scope rule

A scope's `profileKey` becomes immutable once the scope has ownership data.

New profile versions may be used by new scopes without rewriting existing scopes.

Changing the profile of an existing populated scope is not a normal runtime operation because it can change public URLs and lookup semantics. Any future profile migration must be a separately designed, explicit migration with collision/rewrite proof; it must not happen implicitly.

## 9.3 Custom profiles

The RC should expose a clear extension contract for custom profiles.

A custom persisted profile must have a stable, versioned key and the consumer becomes responsible for keeping that key's observable behavior compatible for as long as persisted scopes reference it.

If a persisted scope references an unavailable profile, operations requiring that profile must fail closed with a semantic configuration/profile error.

## 9.4 Determinism requirement

A built-in profile must have canonical test vectors.

A profile described as deterministic must not silently delegate observable output to environment-dependent transliteration/case behavior without defining and testing the compatibility contract.

This is especially important for transliteration engines whose mappings may vary across dependency or runtime versions.

---

# 10. Unicode and Transliteration

Unicode is first-class RC scope.

Arabic and other non-Latin scripts must not be forced through ASCII transliteration.

A Unicode-oriented built-in profile should support behavior equivalent to:

```text
آيفون ١٧ برو
→ آيفون-١٧-برو
```

An ASCII/transliteration-oriented profile may support behavior equivalent to:

```text
Über Café
→ uber-cafe
```

The exact built-in profile names and exact normalization/transliteration algorithms are Blueprint decisions.

The language hint used by a transliteration strategy, if any, is separate from the scope's opaque `localeKey` unless an explicit caller/adapter deliberately maps them.

---

# 11. Unicode Normalization and Security Policy

Equivalent Unicode representations must not create accidental duplicate identities when the selected profile says they are equivalent.

Each built-in profile must explicitly define and test its normalization and security behavior.

At minimum, the Blueprint must decide and protect handling for:

```text
invalid UTF-8
NUL
control characters
path separators
empty normalized results
Unicode normalization equivalence
format/bidi controls relevant to the selected profile
```

The package must not apply a vague blanket ban on all mixed-script input because legitimate multilingual values can exist:

```text
iPhone-١٧
```

Likewise, blanket rejection of every Unicode formatting code point is not assumed correct for all writing systems.

The RC architecture should provide an explicit policy boundary so stricter security rules can be applied without changing ownership/persistence architecture.

The exact default policy is a Blueprint decision and must be backed by test vectors rather than informal terms such as "dangerous characters".

---

# 12. Reserved Slugs

Reserved identifiers are RC scope.

Recommended extension contract concept:

```text
ReservedSlugPolicyInterface
```

Examples of Host-reserved values:

```text
admin
api
login
assets
search
```

The Host owns the actual vocabulary/patterns.

The package may provide reusable policies such as:

```text
exact-match
pattern-based
composite
```

Reservation blocks new claims/allocations.

A slug already legitimately owned before a reservation policy changes remains owned and resolvable until the Host explicitly changes/releases it. A configuration change must not silently invalidate persisted ownership.

---

# 13. Generation Failure and Fallbacks

Automatic generation must not invent undocumented fallback values.

Example source:

```text
❤️🔥
```

If the selected profile produces no valid slug, generation should fail with a semantic failure such as:

```text
SlugCannotBeGeneratedException
```

A consumer that wants fallback behavior may provide an explicit strategy contract.

The package must not silently invent values such as:

```text
item-123
```

unless configured behavior explicitly defines that result.

---

# 14. Length Handling

The final storage limit and profile limits are Blueprint/schema decisions.

Whatever limit is selected, collision suffixing must stay inside it.

If the base candidate is at the maximum length and allocation needs:

```text
-2
```

the base must be shortened safely before appending the suffix.

The final canonical slug must always satisfy the declared profile/storage limit.

Length rules must operate safely on Unicode code points/grapheme policy as defined by the selected profile; byte-based truncation must not corrupt UTF-8.

---

# 15. Database Equality / Collation Contract

Slug equality must be controlled by the package's canonical normalization contract, not by accidental database linguistic comparison.

Conceptually:

```text
raw input
→ profile normalization
→ canonical slug identity
→ exact persisted uniqueness
```

The schema must use comparison semantics compatible with exact canonical identity after normalization.

The exact MySQL/MariaDB collation/column strategy, or equivalent strategy for any other declared driver, is a Blueprint decision and must be integration-tested.

The package must not rely on a case/accent-insensitive collation to perform normalization implicitly.

---

# 16. Persistence Model — Conceptual Foundation

The recommended persistent model contains four logical concepts:

```text
Scope
Binding
Registry
History
```

Exact DDL is intentionally not locked by this discussion file.

All package tables must follow the package table prefix:

```text
maa_slug_
```

No table may FK or JOIN Host tables.

Package-local relationships/FKs, if used, are a schema/Blueprint decision.

---

# 17. `maa_slug_scopes`

Represents logical ownership scopes and their immutable profile configuration.

Conceptually:

```text
id
namespace
locale_key
context_key
profile_key
created_at
```

Required logical uniqueness:

```text
UNIQUE(namespace, locale_key, context_key)
```

`profile_key` is deliberately not part of that logical unique identity.

A populated scope cannot silently switch profiles.

---

# 18. `maa_slug_bindings`

Represents:

```text
EntityReference + SlugScope
```

Conceptually:

```text
id
scope_id
entity_type
entity_key
current_registry_id
status
revision
created_at
updated_at
```

Required logical uniqueness:

```text
UNIQUE(scope_id, entity_type, entity_key)
```

`current_registry_id` is conceptual: it expresses that the binding has one authoritative current canonical slug when active.

The exact bootstrap/nullability/constraint sequence for creating a new binding and its first registry claim must be resolved in the Blueprint so the schema does not depend on an impossible circular insert.

## 18.1 Revision

`revision` provides optimistic concurrency protection against silent lost updates to the same binding.

Mutation contracts may carry:

```text
expectedRevision
```

The exact commands that require it are a Blueprint/public-API decision.

---

# 19. `maa_slug_registry`

The registry is the authoritative ownership set for slugs that are currently claimed/reserved by a binding.

Conceptually:

```text
id
scope_id
binding_id
slug
lifecycle-role metadata
claimed_at
```

Critical uniqueness:

```text
UNIQUE(scope_id, slug)
```

The registry metadata must be sufficient to distinguish at least:

```text
current canonical
historical canonical
active alias
retired alias
```

The exact column model is deliberately **not** reduced to a single boolean such as `is_active_alias`, because that cannot distinguish a historical canonical slug from a retired alias that was never canonical.

Currentness may be anchored by the binding's current pointer, with registry metadata retaining the information required for alias/history semantics. Exact redundancy and integrity rules are Blueprint decisions.

---

# 20. `maa_slug_history`

History records immutable **normal lifecycle events**.

It is a domain-specific history table, not a generic log/event sink.

Possible event concepts include:

```text
assigned
changed
restored
alias_added
alias_retired
alias_reactivated
alias_promoted
deactivated
reactivated
ownership_released
adopted
```

Conceptually it needs enough stable snapshot information to remain understandable even if live registry ownership is later released.

Possible data concepts:

```text
id
binding identity/reference
event_type
slug snapshot
previous slug snapshot
occurred_at
actor_key
reason
correlation_key
```

The history design must not depend exclusively on live registry-row references if those rows can be released from authoritative ownership later.

No generic unbounded JSON dump is required for normal lifecycle semantics.

---

# 21. Ownership Invariant

Default invariant:

> Once a slug is claimed by a binding inside a scope, another binding cannot claim it while that ownership remains registered.

Example:

```text
product:100

foo
→ bar
```

Both `foo` and `bar` remain owned by the same binding under normal lifecycle rules.

Another entity cannot claim `foo` merely because it stopped being current.

The original binding may restore `foo` without receiving `foo-2`.

This prevents old URLs from silently becoming identifiers for unrelated entities.

---

# 22. Ownership Release vs Destructive Purge

The previous design used one ambiguous `purge` concept for two different requirements. They must be separated.

## 22.1 Ownership release

An explicit ownership-release operation means:

- the binding intentionally surrenders its registered slug claims;
- surrendered slugs may subsequently be claimed by another binding;
- those slugs stop participating in authoritative runtime resolution for the released binding;
- normal immutable audit/history may remain as historical evidence;
- the operation must be explicit and strongly documented because it breaks the normal permanent-ownership invariant.

A release must never happen as a side effect of deactivation or alias retirement.

## 22.2 Destructive purge / erasure

A separate maintenance operation may be required to erase package-owned data for a binding, including audit/history where the erasure contract requires that.

This is outside normal lifecycle semantics and is intentionally destructive.

The exact purge/erasure contract — including whether any non-identifying evidence may remain — is a Blueprint decision influenced by privacy/data-retention requirements.

**Immutability therefore applies to retained normal-lifecycle history; it does not claim that an explicit data-erasure operation can never delete history.**

This distinction removes the contradiction between "immutable history" and "destructive purge".

---

# 23. Exact Claim vs Automatic Allocation

The RC must model these as different intents.

## 23.1 Exact claim

The caller requires one specific normalized slug.

Example:

```text
iphone-pro
```

If another binding owns it or policy rejects it, the operation fails.

The package must not silently convert an exact request to:

```text
iphone-pro-2
```

Typical use cases:

- manually chosen slug;
- deterministic import;
- administrative change;
- migration/adoption;
- external contract requiring a known value.

## 23.2 Automatic allocation

The caller permits suffix allocation after generation/normalization.

Example:

```text
iphone-pro
iphone-pro-2
iphone-pro-3
```

This intent is suitable for title/source-driven generation.

Exact claim and automatic allocation must not share an ambiguous public operation whose behavior depends on hidden defaults.

---

# 24. Collision Handling

Database uniqueness is the final concurrency authority.

An availability query is advisory only.

Unsafe correctness assumption:

```text
SELECT available?
→ yes
→ INSERT
```

Two concurrent processes may both observe the same value as available.

Authoritative allocation pattern:

```text
candidate
→ attempt claim
→ exact unique constraint
→ proven duplicate conflict
→ next candidate when auto-allocation permits it
```

Retries must be bounded.

Semantic duplicate conversion must use documented driver-specific evidence.

For MySQL/MariaDB, driver code `1062` is an example of duplicate-key evidence. SQLSTATE class `23` by itself is not sufficient because it represents a wider integrity-constraint category.

The first RC must explicitly declare which database drivers its persistence adapter supports. Every declared driver must have its own correct duplicate/conflict evidence and integration tests; this document does not claim universal PDO portability.

---

# 25. Concurrency Protection

Two independent race classes must be protected.

## 25.1 Competing bindings for the same slug

Example:

```text
Process A → claim iphone
Process B → claim iphone
```

The exact database uniqueness constraint decides the winner.

The loser:

- receives a semantic conflict for exact claim; or
- advances to the next allowed candidate for auto allocation.

## 25.2 Concurrent mutations of the same binding

Example:

```text
current = foo
revision = 5

Writer A: foo → bar
Writer B: foo → baz
```

If both started from revision `5`, only one may silently succeed.

After one commits revision `6`, the stale mutation must fail with a revision conflict rather than overwrite the winning change.

Persistence may additionally use row locking where required to make multi-row lifecycle transitions safe.

Both race classes require real concurrency verification, not only repository mocks.

---

# 26. Transaction Model

Every lifecycle mutation spanning multiple persistence changes must be atomic.

## 26.1 No caller transaction

The package owns the transaction:

```text
BEGIN
...
COMMIT
```

On failure it rolls back its owned transaction and rethrows the original/semantically converted failure according to the package exception contract.

## 26.2 Caller-owned transaction already active

The package must not commit or roll back the Host transaction.

For drivers where the package promises savepoint participation, the package should isolate its atomic operation using a collision-safe package-owned savepoint:

```text
SAVEPOINT
...
RELEASE SAVEPOINT
```

Failure:

```text
ROLLBACK TO SAVEPOINT
```

This prevents partial package state if the Host catches a slug exception and continues its outer transaction.

Savepoint syntax/capability is driver-specific and therefore part of each supported persistence-adapter contract.

If a declared driver cannot satisfy the promised nested-operation atomicity contract, that limitation must be explicit rather than silently weakening atomicity.

---

# 27. Canonical Lifecycle

Conceptual lifecycle operations should cover behavior equivalent to:

```text
assignExact
assignGenerated
changeExact
changeGenerated
restoreHistorical

deactivate
reactivate
releaseOwnership
```

Method/class names are not locked yet.

The package does not watch Host model fields. If the Host wants a slug to change when a title changes, the Host explicitly invokes a change operation.

If the Host wants immutable permalinks, it does not invoke a title-driven slug change.

---

# 28. Aliases

Aliases are first-class and are not equivalent to canonical history.

Example:

```text
Current canonical:
iphone-17-pro

Active aliases:
iphone-pro
apple-iphone-pro
```

Definitions:

- **Historical canonical:** a slug that was canonical in the past.
- **Active alias:** an alternate slug that currently resolves to the binding.
- **Retired alias:** an alias that no longer resolves as active but whose ownership remains registered until explicit ownership release.

Conceptual alias operations should cover:

```text
addAlias
retireAlias
reactivateAlias
promoteAliasToCurrent
```

`retireAlias` is preferred conceptually over a misleading "delete alias" operation because normal retirement does not free ownership for another entity.

Promotion must preserve history of the previous canonical slug and must not create ownership duplication.

---

# 29. Deactivation / Reactivation

Deactivation must not release slug ownership.

When a binding becomes inactive:

- current ownership remains;
- historical ownership remains;
- active/retired alias ownership remains;
- lifecycle history remains;
- resolution can report that the matched binding is inactive.

The Host decides whether an inactive match becomes:

```text
404
410
redirect
other behavior
```

Reactivation restores the binding's active status; exact rules for whether an inactive binding must retain a current slug are Blueprint decisions.

---

# 30. Resolution Model

Resolution must not collapse independent facts into one ambiguous status enum.

The result should separate at least:

```text
matchKind
bindingStatus
canonicality
```

## 30.1 Match kind

Conceptual values:

```text
CURRENT
ALIAS
HISTORICAL
RETIRED_ALIAS
NONE
```

## 30.2 Binding status

Conceptual values when a binding exists:

```text
ACTIVE
INACTIVE
```

This allows valid combinations such as:

```text
HISTORICAL + INACTIVE
ALIAS + ACTIVE
CURRENT + INACTIVE
```

without inventing one overloaded enum.

## 30.3 Canonicality

Resolution receives a decoded input segment, normalizes it with the scope's profile, performs lookup on canonical identity, and can report whether the caller's supplied representation already equals the canonical representation expected for that match/current target.

Example:

```text
stored/current:
iphone-pro

incoming decoded segment:
iPhone-Pro

normalized lookup:
iphone-pro

matchKind:
CURRENT

isCanonicalRequest:
false
```

The Host decides whether that difference warrants an HTTP redirect or any other action.

## 30.4 Suggested result information

A result may need information equivalent to:

```text
requestedSegment
normalizedLookupSlug
matchedSlug
matchKind
bindingStatus
currentSlug
entity
scope
revision
isCanonicalRequest
```

Exact DTO shape is a Blueprint decision.

Historical/alias matches should resolve directly to the current binding state rather than forming redirect chains such as:

```text
old1 → old2 → old3 → current
```

---

# 31. Availability API

Availability checks are useful but never authoritative under concurrency.

A result should be richer than a boolean.

Possible high-level classifications:

```text
AVAILABLE
OWNED_BY_SAME_BINDING
OWNED_BY_OTHER_BINDING
RESERVED
INVALID
```

Additional ownership metadata may distinguish current/history/alias/retired state without multiplying public enums unnecessarily.

Documentation must state:

> Availability is advisory. Only an actual claim/allocation operation provides an authoritative concurrency-safe outcome.

---

# 32. Legacy Adoption

Existing applications may already contain:

```text
current slugs
historical canonical slugs
aliases
```

Migration/adoption is first-RC scope.

Conceptual operations:

```text
adoptCurrent
adoptHistorical
adoptAlias
```

Adoption should support original lifecycle timestamps when they are known and contractually valid.

Adoption may provide narrowly controlled migration behavior for rules such as newly introduced reserved words, but it must never bypass ownership uniqueness or create ambiguous live claims.

A generic ETL/import framework is not required. The package only needs a deliberate domain adoption API that lets Hosts migrate trustworthy legacy slug state.

---

# 33. Ownership Release and Reuse Semantics

Normal lifecycle operations do **not** free slugs for unrelated entities.

Cross-entity reuse becomes possible only after explicit ownership release (or destructive purge where applicable).

Consequences must be documented clearly:

- historical runtime resolution for released slugs is intentionally no longer authoritative for the old binding;
- another binding may subsequently claim the released slug;
- retained audit history is not consulted as live ownership;
- the operation therefore represents a deliberate break from permanent old-URL protection.

This tradeoff must be explicit in the public contract so a Host cannot accidentally turn an old URL into a different entity identifier.

---

# 34. Management / Query API

Persisted package data needs supported PHP-level read/query contracts for support, auditing, migrations, and management integrations.

The package should support queries equivalent to:

```text
get binding
get current slug
list aliases
get history
inspect registry ownership
search bindings
search registry claims
inspect scope
```

List/search filters should use explicit Criteria contracts.

Where pagination is needed, the package should use the stable Maatify persistence pagination capability rather than create a duplicate package-local pagination abstraction.

The package provides no Admin UI.

---

# 35. Mutation Results and Cross-Domain Integration

Mutations should return stable domain results rather than force Host code to reread internal tables.

A change result may expose information equivalent to:

```text
entity
scope
previousSlug
currentSlug
revision
changeType
```

This allows the Host to coordinate other domains explicitly.

Example:

```text
Slug change result
+
Host decision
+
SEO redirect creation
```

The Slug package must not call `php-seo` directly.

Core behavior must not require a particular event dispatcher.

If event publication is later useful, it should be an adapter/integration concern unless the Blueprint identifies a concrete package-owned requirement.

---

# 36. Audit Context

Lifecycle mutation contracts may carry limited optional Host-provided audit context such as:

```text
actorKey
reason
correlationKey
```

These values are opaque to the package.

The package must not know user tables, authentication models, request objects, or Host actor types.

Exact length/nullability/privacy rules are Blueprint/schema decisions.

---

# 37. Exception Model

The package should expose a stable semantic exception taxonomy built on `maatify/exceptions`.

Possible package exceptions include:

```text
SlugExceptionInterface
SlugInvalidArgumentException
SlugNotFoundException
SlugConflictException
SlugAlreadyClaimedException
SlugReservedException
SlugCannotBeGeneratedException
SlugRevisionConflictException
SlugProfileNotFoundException
```

Exact hierarchy/names remain Blueprint decisions.

Rules already considered mandatory:

- package-defined exceptions implement the package marker contract;
- unknown infrastructure failures are not blindly wrapped;
- errors are never swallowed;
- duplicate-key conversion occurs only with driver-specific proof;
- unknown `PDOException` instances may propagate unchanged when no package-owned semantic classification is proven;
- when wrapping is appropriate, the original throwable is preserved as `previous` where supported.

---

# 38. Clock and Time

Lifecycle timestamps must use:

```text
Maatify\SharedCommon\Contracts\ClockInterface
```

The package must not mutate PHP's global timezone.

The Host owns application timezone configuration.

Timestamp storage format/timezone policy is a schema/Blueprint decision and must be explicit.

---

# 39. Expected Shared Runtime Dependencies

The dependency graph should remain minimal and explicit.

Expected Maatify shared packages where their stable APIs are used:

```text
maatify/exceptions
maatify/shared-common
maatify/persistence
```

Purposes:

- `maatify/exceptions` — package-owned exception hierarchy;
- `maatify/shared-common` — `ClockInterface`;
- `maatify/persistence` — stable pagination capabilities for applicable management queries.

The package must not copy shared Maatify capabilities into local duplicates.

Exact minimum stable versions belong in the Composer/Blueprint work and must correspond to actually published APIs.

---

# 40. Database Driver Policy

The package is Host/framework agnostic; that does not mean its persistence implementation must pretend every PDO driver behaves identically.

Before implementation, the Blueprint must declare:

- which database drivers are supported by RC1;
- exact schema assets per supported driver if they differ;
- duplicate-key detection rules;
- locking behavior;
- transaction behavior;
- savepoint behavior;
- collation/equality behavior;
- integration-test matrix.

Only declared/tested drivers are supported.

Examples in this discussion mentioning MySQL/MariaDB code `1062` are examples of correct driver-specific classification, not a promise that every PDO driver is supported.

---

# 41. Conceptual Public Capability Surface

Final classes/method names must be decided in the Blueprint, but RC1 should cover capabilities equivalent to the following.

## Profile / normalization

```text
normalize
generate
validate
resolve profile
```

## Availability / allocation

```text
checkAvailability
claimExact
allocateGenerated
```

## Lifecycle

```text
assignExact
assignGenerated
changeExact
changeGenerated
restoreHistorical
deactivate
reactivate
releaseOwnership
```

## Alias

```text
addAlias
retireAlias
reactivateAlias
promoteAliasToCurrent
```

## Resolution

```text
getCurrent
resolve
```

## Adoption

```text
adoptCurrent
adoptHistorical
adoptAlias
```

## Management

```text
getBinding
getHistory
listAliases
searchBindings
searchRegistry
```

## Maintenance

```text
purge / erase
```

The public API should prefer domain operations with explicit intent over generic CRUD terminology.

---

# 42. Conceptual Source Organization

The standard's organizing direction is Domain → Capability → Layer, without ceremonial empty folders.

A possible capability map is:

```text
src/
    Profile/
    Generation/
    Scope/
    Identity/
    Allocation/
    Lifecycle/
    Alias/
    Resolution/
    History/
    Adoption/
    Management/
    Persistence/
    Exception/
```

This is not a locked directory tree.

The Blueprint should create directories only where real responsibilities justify them.

---

# 43. Testing and RC Readiness

RC1 cannot be considered complete because unit tests and PHPStan pass.

Required evidence must include the applicable combination of:

- unit tests;
- real integration tests for every declared persistence driver;
- public API/system-level workflow tests;
- Consumer Verification Harness from a separate Composer root;
- real concurrency verification for race-prone invariants;
- transaction/savepoint verification for supported drivers;
- clean consumer installation/resolution;
- production PSR-4 autoload verification;
- PHPStan level max;
- Composer validation;
- latest/lowest dependency verification where required by the package standards/CI contract.

Important scenario families include:

```text
English generation
Arabic generation
Unicode generation
ASCII/transliteration generation
Unicode normalization equivalence
normalization idempotence
invalid UTF-8
profile-defined control/format policy
reserved slugs
empty generation result
exact claim
automatic allocation
collision suffixing
maximum-length suffixing
Unicode-safe truncation
same slug in different scopes
same slug in same scope
same entity restore
cross-entity historical reuse prevention
ownership release and later reuse
alias creation
alias retirement
alias reactivation
alias promotion
historical resolution
current resolution
alias resolution
retired-alias resolution semantics
active binding resolution
inactive binding resolution
canonical request
non-canonical request
legacy current adoption
legacy history adoption
legacy alias adoption
concurrent exact claim
concurrent auto allocation
concurrent same-binding update
revision conflict
package-owned transaction rollback
caller-owned transaction participation
savepoint rollback
outer transaction rollback
profile-not-found failure
reserved-policy changes with existing ownership
management pagination
consumer installation
```

A fixed regression must receive regression protection at the appropriate behavioral level.

---

# 44. RC1 Scope Summary

| Domain | RC1 foundation |
|---|---|
| Profile | versioned stable generation/normalization contracts |
| Generation | source → candidate |
| Normalization | canonical slug identity |
| Unicode | multilingual + profile-defined normalization/security |
| Scope | namespace + locale + context; immutable profile configuration |
| Identity | Host-provided opaque entity reference |
| Allocation | exact claim + automatic allocation |
| Availability | advisory ownership/policy classification |
| Ownership | authoritative scope-local registry |
| Lifecycle | assign/change/restore/deactivate/reactivate/release |
| Alias | add/retire/reactivate/promote |
| Resolution | match kind + binding state + canonicality |
| History | immutable retained normal-lifecycle timeline |
| Adoption | legacy current/history/alias adoption |
| Management | supported queries + shared pagination |
| Persistence | package-owned PDO infrastructure for declared drivers |
| Transactions | owned transaction + documented caller-transaction participation |
| Concurrency | unique-claim races + same-binding revision protection |
| Maintenance | explicit destructive erasure contract |
| Exceptions | stable semantic taxonomy |
| Clock | shared `ClockInterface` |
| Testing | unit + integration + system + consumer + concurrency |

These are RC1 foundation capabilities, not a list of deliberately deferred core redesigns.

---

# 45. Explicit Non-Goals

These are outside the package, not merely postponed:

```text
Router
Route definitions
Full path builder
Full URL builder
URI percent encoding/decoding
HTTP redirect policy
SEO redirect persistence
Canonical tags
hreflang
Framework middleware
Laravel model integration as a core dependency
Symfony model integration as a core dependency
Slim integration as a core dependency
ORM listeners
Host model traits
Automatic title/name monitoring
Host entity existence validation
Host database joins
Host foreign keys
Authentication
Authorization
Admin UI
SEO policy
Application bootstrap
```

Framework/application adapters can be separate integrations if a concrete need appears.

---

# 46. Architectural Invariants Proposed for Locking

Unless review identifies a concrete defect, the Blueprint should preserve these rules:

1. The package is an authoritative Slug Lifecycle Engine, not only a slug generator.
2. Stateless generation/normalization can run without persistence configuration.
3. Persisted lifecycle is fully package-owned when the Host chooses to use it.
4. A slug is a decoded canonical path-segment token, not a full path/URL or percent-encoded transport string.
5. Entity identity is Host-provided, stable, and opaque.
6. No Host FK or JOIN is allowed.
7. Scope is first-class.
8. Logical scope identity is namespace + localeKey + contextKey.
9. `profileKey` is immutable scope configuration, not a uniqueness dimension.
10. Versioned profiles define stable observable generation/normalization behavior.
11. Current canonical, historical canonical, active alias, and retired alias ownership share one authoritative scope-local registry.
12. Normal lifecycle changes do not release ownership.
13. Historical canonical slugs remain owned by the same binding under normal lifecycle.
14. Retired aliases remain owned under normal lifecycle.
15. The same binding can restore its historical canonical slug.
16. Cross-binding reuse requires explicit ownership release or destructive erasure semantics.
17. Ownership release and destructive purge are different concepts.
18. Exact claim and automatic allocation are different intents.
19. Database uniqueness is the final claim authority.
20. Availability checks are advisory.
21. Same-slug races are protected by database uniqueness.
22. Same-binding races are protected by revision/locking semantics.
23. Multi-row lifecycle mutations are atomic.
24. Caller-owned transactions must not be committed or rolled back by the package.
25. Savepoint participation, where promised, is driver-specific and tested.
26. Retained normal-lifecycle history is immutable.
27. Aliases are first-class and distinct from canonical history.
28. Resolution separates match kind from binding status.
29. Canonical/non-canonical request representation is reportable without making HTTP decisions.
30. Runtime historical/alias resolution points directly to current binding state, not redirect chains.
31. Unicode is first-class.
32. Arabic works without forced ASCII conversion.
33. ASCII/transliteration behavior is a separate profile/strategy concern.
34. Built-in deterministic profiles have canonical test vectors and controlled dependencies.
35. Reserved-slug policy is supported from RC1.
36. Legacy adoption is supported from RC1.
37. Explicit ownership release is the only normal mechanism that makes retained claims reusable by another binding.
38. The package exposes supported query/management contracts for package-owned data.
39. Time uses `ClockInterface`.
40. Shared pagination uses the Maatify persistence capability where applicable.
41. Package exceptions use the Maatify exception hierarchy.
42. Unknown infrastructure failures are not blindly wrapped.
43. Supported database drivers are declared rather than assumed portable.
44. The Slug package has no dependency on SEO.
45. SEO has no dependency on Slug.
46. Host/adapter code owns integrations between Slug and other domains.

---

# 47. Open Decisions That Must Be Closed in the Blueprint

The architectural direction is intentionally broad, but the following items cannot remain ambiguous when implementation starts:

1. Exact RC1 supported database driver(s).
2. Exact built-in profile names and normalization rules.
3. Unicode normalization form/policy for each built-in profile.
4. Transliteration engine/data and deterministic compatibility contract.
5. Default Unicode security policy and extension interface.
6. Exact slug/storage length and Unicode-safe length semantics.
7. Exact database collation/equality strategy per supported driver.
8. Exact scope canonical-empty representation.
9. Exact registry state/role columns and integrity constraints.
10. Exact binding bootstrap/current-pointer constraints.
11. Exact binding status model.
12. Exact history snapshot model.
13. Exact ownership-release persistence behavior.
14. Exact destructive purge/erasure behavior.
15. Exact alias promotion/retirement/reactivation rules.
16. Exact revision/locking rules per mutation.
17. Exact transaction/savepoint adapter behavior.
18. Exact public commands, Criteria, DTOs, enums, interfaces, and exceptions.
19. Exact legacy-adoption rules and timestamp validation.
20. Exact dependency minimum versions based on published stable APIs.
21. Exact schema/index plan and real concurrency proof strategy.
22. Exact standards adoption snapshot/manifest for the repository.
23. Exact RC execution plan and acceptance criteria.

These are Blueprint decisions, not reasons to reopen the core package boundary.

---

# 48. Working Definition

> **Maatify Slug provides deterministic, host-agnostic slug generation, normalization, scoped ownership, allocation, lifecycle, aliases, history, resolution, adoption, and concurrency-safe persistence for PHP applications.**

---

# 49. Review Exit Criteria for This Discussion Draft

This discussion draft is ready to be replaced by the formal Blueprint only when review confirms that:

- the package boundary is coherent;
- no core slug use case requires a different identity/scope/ownership model;
- no documented invariant contradicts another invariant;
- no persistence concept depends on impossible schema semantics;
- URL/HTTP/SEO responsibilities remain outside the package;
- Unicode and transliteration contracts are implementable and testable;
- release/reuse/history semantics are explicit;
- transaction/concurrency promises can be proven on declared drivers;
- standards obligations have a clear adoption path;
- remaining questions are implementation/contract details suitable for the Blueprint rather than unresolved architectural holes.

After those conditions are met:

1. accepted decisions move into the formal Blueprint and RC implementation plan;
2. durable package/reference documentation is created according to the applicable standards;
3. this temporary discussion file is deleted before the preparation workstream closes.
