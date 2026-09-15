# Maatify Slug Library — RC Concept Discussion Draft

> **Status:** Discussion Draft  
> **Purpose:** Define the complete professional scope and architectural direction for the first Release Candidate of the standalone Maatify Slug library before producing the formal Blueprint.  
> **Repository / package naming:** To be finalized during Blueprint discussion.  
> **Document intent:** This file is intentionally editable and expected to evolve through discussion.

---

## 1. Objective

The library must not be treated as a simple `slugify()` helper or as a replacement for the removed SEO slug-history feature.

The intended package is:

> **An authoritative, standalone Slug Lifecycle Engine for PHP applications.**

The first RC should already contain the complete set of capabilities required for professional slug ownership, allocation, history, lookup, migration, concurrency safety, and long-term stability.

The goal is to avoid shipping an incomplete core that later requires redesigning:

- identity,
- scope,
- persistence,
- uniqueness,
- lifecycle,
- history,
- transaction behavior,
- resolution,
- or public contracts.

Future releases should primarily add new strategies, profiles, adapters, or integrations without changing the architectural foundation.

---

# 2. Package Boundary

The library owns the **slug domain**.

It does **not** own the entire URL or routing domain.

## 2.1 Owned by the library

The library should own:

- slug generation,
- normalization,
- validation,
- Unicode handling,
- ASCII/transliteration strategies,
- slug scopes,
- availability checks,
- exact slug claiming,
- automatic slug allocation,
- collision handling,
- current slug ownership,
- historical slug ownership,
- aliases,
- lifecycle history,
- restoring historical slugs,
- current slug lookup,
- historical slug lookup,
- alias lookup,
- canonical/non-canonical resolution,
- deactivation,
- reactivation,
- destructive purge when explicitly requested,
- legacy adoption/import,
- management queries,
- persistence,
- transaction guarantees,
- concurrency protection,
- auditing context,
- stable domain result contracts.

## 2.2 Explicitly outside the library

The library must not own:

- application routing,
- full URL generation,
- route definitions,
- HTTP controllers,
- HTTP middleware,
- HTTP status decisions,
- `301`, `302`, `404`, or `410` policy,
- SEO redirect records,
- canonical tags,
- hreflang,
- framework-specific entity hooks,
- ORM listeners,
- automatic observation of model field changes,
- Host entity existence validation,
- Host entity persistence,
- Host foreign keys,
- Host table joins,
- authentication,
- authorization,
- Admin UI,
- SEO policy,
- tenant/application bootstrap.

If a Host wants to connect slug changes to SEO redirects, routing, or another domain, the Host or an external adapter owns that integration.

There must be no direct dependency between the Slug package and the SEO package.

---

# 3. Two Usage Levels

The package should support two legitimate usage modes.

## 3.1 Stateless Slug Core

Consumers may use only generation and normalization:

```text
Input:
iPhone 17 Pro Max

Output:
iphone-17-pro-max
```

No persistence should be required for this use case.

## 3.2 Authoritative Lifecycle Mode

Consumers that require persistence can use the full lifecycle:

```text
Generate
→ Claim
→ Current
→ Change
→ History
→ Resolve
```

The package must not force simple slug-generation consumers to use a database.

At the same time, persistent applications must not need another package to implement the authoritative slug lifecycle.

---

# 4. Slug Scope

Slug uniqueness cannot be globally hardcoded.

The package requires a first-class value object representing the uniqueness boundary.

Proposed conceptual model:

```text
SlugScope

namespace
localeKey
contextKey
profileKey
```

## 4.1 namespace

Required.

Examples:

```text
product
category
article
username
global-content
```

This defines the logical slug namespace.

## 4.2 localeKey

Optional from the Host perspective, but stored canonically.

Examples:

```text
ar
en
ar-EG
12
```

The value is opaque to the package.

The package must not assume that this is:

- a language database ID,
- a BCP-47 language tag,
- or any specific Host representation.

## 4.3 contextKey

Optional additional uniqueness boundary.

Examples:

```text
tenant:5
store:2
parent:52
site:ep4n
tenant:5|parent:52
```

This allows the same package architecture to support:

- globally unique products,
- categories unique per parent,
- content unique per locale,
- usernames unique globally,
- tenant-specific slugs,
- site-specific slugs,
- hierarchical content scopes.

## 4.4 profileKey

The normalization/generation profile used by the scope.

This must be stable and versioned.

Example:

```text
unicode-v1
ascii-v1
```

A scope must not silently change its slug-normalization semantics after production data exists.

## 4.5 No nullable uniqueness ambiguity

The persistence model should not depend on nullable uniqueness semantics.

Missing locale/context values should be represented canonically rather than relying on database `NULL` behavior inside uniqueness constraints.

---

# 5. Entity Identity

The package must not assume Host entity IDs are integers.

The package should use an opaque Host-provided entity identity.

Proposed model:

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

`entityKey` must be treated as an opaque stable string.

The package must not:

- verify the entity exists,
- join to the Host table,
- add an FK,
- infer the entity's storage type.

The package only knows:

> These slug records belong to this Host-provided entity reference.

---

# 6. Persistence Model

The recommended persistence foundation contains four logical tables.

---

## 6.1 `maa_slug_scopes`

Represents exact uniqueness and normalization boundaries.

Conceptually:

```text
id
namespace
locale_key
context_key
profile_key
created_at
```

The scope identity should be unique.

Purpose:

- centralize scope identity,
- avoid repeating large scope fields in every slug row,
- bind persisted data to a stable profile,
- support global, locale, tenant, site, parent, or compound scope models.

---

## 6.2 `maa_slug_bindings`

Represents the relationship between:

```text
Entity + Scope
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

Required uniqueness:

```text
UNIQUE(scope_id, entity_type, entity_key)
```

This guarantees one authoritative binding per Host entity inside an exact scope.

### `revision`

`revision` is required for optimistic concurrency protection.

It prevents silent lost updates when two processes attempt to change the same entity slug concurrently.

---

## 6.3 `maa_slug_registry`

This is the permanent slug ownership registry.

Every slug ever claimed by a binding should exist here.

Conceptually:

```text
id
scope_id
binding_id
slug
is_active_alias
claimed_at
```

Critical uniqueness:

```text
UNIQUE(scope_id, slug)
```

All of the following share the same ownership constraint:

- current slugs,
- historical slugs,
- active aliases.

Example lifecycle:

```text
product:100

iphone
→ iphone-pro
→ iphone-17-pro
```

All three values remain owned by `product:100`.

Another entity must not later silently acquire `iphone`.

---

## 6.4 `maa_slug_history`

Immutable domain history.

This is not a generic logging table.

It records slug lifecycle transitions.

Possible event types:

```text
assigned
changed
restored
alias_added
alias_removed
deactivated
reactivated
adopted
purged
```

Conceptually:

```text
id
binding_id
event_type
from_registry_id
to_registry_id
occurred_at

actor_key
reason
correlation_key
```

History must be immutable.

No generic JSON dumping should be required for normal lifecycle information.

---

# 7. Slug Ownership Invariant

The recommended primary invariant is:

> Once a slug has been claimed by an entity inside a scope, another entity cannot claim it unless the original binding is explicitly purged.

Example:

```text
product:100

foo
→ bar
```

`foo` remains owned by `product:100`.

Another entity cannot claim `foo`.

However, the original entity may restore it:

```text
foo
→ bar
→ foo
```

The restore operation must reuse the original ownership rather than allocate:

```text
foo-2
```

---

# 8. No Normal Soft Delete for Ownership

Slug ownership must not rely on ambiguous `deleted_at` behavior.

A historical slug is not "deleted".

A deactivated entity is not the same as an available slug.

The package should use explicit lifecycle states such as:

```text
active binding
inactive binding
historical slug
active alias
```

Ownership remains retained.

If the application intentionally wants to erase an entity's slug ownership and allow reuse by other entities, that should be a distinct destructive operation:

```text
purge
```

The purge operation should be:

- explicit,
- destructive,
- irreversible from the library's perspective,
- restricted to valid lifecycle states,
- clearly documented.

---

# 9. Exact Claim vs Automatic Allocation

These must be separate domain operations.

## 9.1 Exact Claim

The caller requires a specific slug.

Example:

```text
iphone-pro
```

If it is unavailable, the operation fails.

The library must not silently change it to:

```text
iphone-pro-2
```

Typical use cases:

- manually entered slugs,
- migration,
- administrative operations,
- imported legacy URLs,
- deterministic integration contracts.

## 9.2 Automatic Allocation

The caller supplies a source or generated candidate and permits collision suffixing.

Example:

```text
iphone-pro
iphone-pro-2
iphone-pro-3
```

This is suitable for automatic title-based generation.

These two intents must never be silently conflated.

---

# 10. Collision Handling

The database uniqueness constraint is the final authority.

An availability query is advisory only.

Unsafe pattern:

```text
SELECT exists
→ decide free
→ INSERT
```

Two concurrent processes can both observe the same slug as free.

Correct approach:

```text
candidate
→ attempt claim
→ database unique constraint
→ proven duplicate
→ next candidate
```

Example:

```text
iphone
iphone-2
iphone-3
```

Retries must be bounded.

Duplicate detection must use documented driver-specific evidence.

For MySQL/MariaDB this includes driver code `1062`.

The package must not treat every SQLSTATE class `23` error as a duplicate-key conflict.

---

# 11. Concurrency Protection

The library must protect two separate race classes.

## 11.1 Competing entities claiming the same slug

Example:

```text
Process A → claim iphone
Process B → claim iphone
```

The database uniqueness constraint decides the winner.

The other operation either:

- fails for exact claim,
- or attempts another candidate for auto allocation.

## 11.2 Concurrent changes to the same entity

Example:

```text
Current:
foo
revision = 5

Admin A:
foo → bar

Admin B:
foo → baz
```

Commands may carry:

```text
expectedRevision = 5
```

After the first successful mutation:

```text
revision = 6
```

The second operation must fail with a revision conflict instead of silently overwriting the first change.

Persistence may additionally use row-level locking during lifecycle mutations.

---

# 12. Transaction Model

Every lifecycle mutation must be atomic.

## 12.1 No caller transaction

The package owns the transaction:

```text
BEGIN
...
COMMIT
```

Failure:

```text
ROLLBACK
```

## 12.2 Caller already owns a transaction

The package must not commit or rollback the Host transaction.

Instead, the package should isolate its own atomic mutation with a savepoint.

Conceptually:

```text
SAVEPOINT
...
RELEASE SAVEPOINT
```

Failure:

```text
ROLLBACK TO SAVEPOINT
```

This protects against partial slug mutations even when the Host catches the package exception and continues its outer transaction.

The package must never silently swallow transaction failures.

---

# 13. Aliases

Aliases are a first-class requirement.

An alias is not the same as history.

Example:

```text
Current:
iphone-17-pro

Active aliases:
iphone-pro
apple-iphone-pro
```

A historical slug means:

> This slug was canonical in the past.

An alias means:

> This slug is a currently valid alternate identifier.

Required operations should conceptually include:

```text
addAlias()
removeAlias()
promoteAliasToCurrent()
```

Aliases share the same permanent ownership registry.

---

# 14. Resolution

Resolution must return a domain result rather than only an entity identifier.

Recommended resolution classifications:

```text
CURRENT
ALIAS
HISTORICAL
INACTIVE
NOT_FOUND
```

A result should be able to expose:

```text
requestedSlug
matchedSlug
currentSlug
entity
scope
revision
isCanonicalRequest
```

Example:

```text
requested:
old-iphone

match:
HISTORICAL

current:
iphone-17
```

The package does not return:

```text
301
```

The Host decides the HTTP behavior.

Historical resolution should map directly to the current state.

The package should not create chains such as:

```text
old1 → old2 → old3 → current
```

---

# 15. Canonical vs Non-Canonical Resolution

Resolution should distinguish between:

```text
matched identity
```

and:

```text
canonical representation
```

Example:

Stored canonical slug:

```text
iphone-pro
```

Incoming request:

```text
iPhone-Pro
```

The package may determine that this resolves to the same canonical identity while reporting:

```text
CURRENT
isCanonicalRequest = false
currentSlug = iphone-pro
```

The Host can then decide whether to redirect, reject, or accept the non-canonical representation.

---

# 16. Versioned Slug Profiles

Slug behavior must not be defined by a single mutable global algorithm.

The package should use named, versioned profiles.

Examples:

```text
unicode-v1
ascii-v1
```

A profile defines behavior such as:

```text
Unicode normalization
case normalization
separator
allowed characters
transliteration
maximum length
suffix behavior
validation rules
```

Why this matters:

Changing normalization logic can change public URLs.

A persisted scope using:

```text
unicode-v1
```

must keep that behavior.

A future algorithm may be introduced as:

```text
unicode-v2
```

without rewriting existing slug semantics.

---

# 17. Unicode Support

Unicode is a first-class requirement, not an optional future feature.

The package must support Arabic and other non-Latin scripts correctly.

Recommended built-in profiles:

## `unicode-v1`

Preserves valid Unicode.

Example:

```text
آيفون ١٧ برو
→ آيفون-١٧-برو
```

## `ascii-v1`

Uses transliteration.

Example:

```text
Über Café
→ uber-cafe
```

The language/locale used for transliteration must be separate from the Host's slug uniqueness `localeKey`.

The package must not assume the two represent the same concept.

---

# 18. Unicode Normalization and Security

Equivalent Unicode representations must normalize consistently.

The package must protect against invalid or dangerous slug input.

At minimum, validation should address:

```text
invalid UTF-8
control characters
NUL
dangerous formatting controls
dangerous bidi controls
```

Mixed-script input must not automatically be forbidden because legitimate values may exist:

```text
iPhone-١٧
```

A stricter optional security policy may later detect suspicious mixed-script or confusable identifiers, but the default behavior should avoid false assumptions about legitimate multilingual content.

---

# 19. Single URL Segment Responsibility

The package manages one URL path segment.

Example:

```text
iphone-17-pro
```

It does not manage:

```text
/en/products/iphone-17-pro?x=1
```

or:

```text
https://example.com/en/products/iphone-17-pro
```

Hierarchical paths remain a Host routing concern.

Example:

```text
electronics
phones
iphone
```

The Slug package owns individual segment identity and scope.

The Host builds:

```text
/electronics/phones/iphone
```

---

# 20. Segment Codec

The package should include strict URL-segment encoding and decoding helpers.

Conceptual API:

```text
encodeSegment()
decodeSegment()
```

Validation should protect against invalid segment semantics such as:

```text
/
\
NUL
invalid percent encoding
encoded slash
.
..
```

The codec must correctly support Unicode segments.

This is URL-segment correctness, not application routing.

---

# 21. Character Policies

The package must not assume every use case requires only:

```text
[a-z0-9-]
```

Legitimate slug-like identifiers may include:

```text
john.doe
user_name
商品-2026
منتج-١٢
```

Profiles should own policy for:

```text
separator
case mode
allowed characters
Unicode behavior
normalization
maximum length
transliteration
```

Built-in profiles may be conservative.

Custom profiles should support additional use cases without changing the persistence architecture.

---

# 22. Reserved Slugs

Reserved identifiers must be supported from the first RC.

Recommended contract:

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

The Host owns the reserved vocabulary.

The package may provide reusable policies such as:

```text
exact-match policy
pattern policy
composite policy
```

Reservation should block new claims.

If a slug was already legitimately claimed before a new reservation rule was introduced, it should remain resolvable unless the Host explicitly changes it.

---

# 23. Empty Generation Result

Automatic generation must never invent an undocumented fallback.

Example:

```text
source:
❤️🔥
```

If the selected profile removes every usable character, the package should fail with a semantic exception such as:

```text
SlugCannotBeGeneratedException
```

A consumer that wants fallback behavior may provide an explicit strategy such as:

```text
FallbackSlugStrategyInterface
```

The package must not silently generate arbitrary values such as:

```text
item-123
```

unless the configured strategy explicitly defines that behavior.

---

# 24. Length Handling

The persistence contract may use a canonical slug storage limit such as:

```text
VARCHAR(255)
```

Profiles may define a smaller maximum.

Collision suffixing must stay inside the maximum length.

Example:

```text
very-long-slug...
```

If adding:

```text
-2
```

would exceed the limit, the base must be safely shortened before appending the suffix.

The final persisted slug must always satisfy the configured limit.

---

# 25. Database Collation

Slug identity must be controlled by application normalization, not accidental database linguistic comparison.

The package should normalize and case-fold according to the selected profile.

The database should then enforce exact canonical slug identity.

The schema should avoid a collation that silently changes slug equality semantics.

The package must explicitly document the chosen storage/collation contract.

---

# 26. Generated vs Manual Slugs

The package must not monitor Host models or entity fields.

It does not know when:

```text
Product.name
```

changes.

Instead, the Host invokes explicit operations.

Conceptually:

```text
assignGenerated()
changeGenerated()

assignExact()
changeExact()
```

If a Host wants immutable permalinks, it simply does not invoke a slug-change operation when the source title changes.

If it wants automatic title-driven updates, the Host calls the generated change operation explicitly.

---

# 27. Deactivate and Reactivate

Deactivation is required.

Example:

```text
deactivate(binding)
```

The binding becomes inactive, but:

- ownership remains,
- history remains,
- current/historical slugs remain attributable to the same entity.

Resolution may return:

```text
INACTIVE
```

The Host decides whether that means:

```text
404
410
redirect
other behavior
```

A later:

```text
reactivate()
```

may restore a previous slug or allow the Host to assign a new current slug.

---

# 28. Legacy Adoption

Migration from existing applications must be supported from the first RC.

A mature application may already have:

```text
current slugs
historical slugs
aliases
```

The package should expose deliberate adoption operations such as:

```text
adoptCurrent()
adoptHistorical()
adoptAlias()
```

Adoption should support preserving original timestamps where appropriate and known.

Legacy adoption may have controlled exceptions for migration policies such as reserved words, but it must never bypass ownership or collision integrity.

A generic import/export framework is not required.

A focused adoption API is sufficient.

---

# 29. Availability API

The package should expose advisory availability checks.

A useful result should be richer than a boolean.

Suggested classifications:

```text
AVAILABLE
OWNED_BY_SAME_ENTITY
OWNED_BY_OTHER_ENTITY
RESERVED
INVALID
```

Documentation must state clearly:

> Availability is advisory. Only an actual claim operation provides an authoritative concurrency-safe result.

---

# 30. Management API

Persisted slug data needs read/query contracts for management, support, migration, and auditing.

The package should support management queries for:

```text
bindings
current slug
aliases
history
registry ownership
scope
```

Query filters should use explicit Criteria objects.

Paginated collections should use the Maatify shared pagination capability rather than introducing package-local pagination contracts.

The package does not provide an Admin UI.

---

# 31. Mutation Result DTOs

Mutation operations should return useful stable domain results.

Example:

```text
SlugChangeResultDTO

entity
scope
previousSlug
currentSlug
revision
changeType
```

This allows Host code to coordinate other domains.

Example:

```text
slug change
+
SEO redirect creation
```

The Host may coordinate both inside a larger transaction.

The Slug package must not directly call the SEO package.

The package should not require an event dispatcher for core behavior.

---

# 32. Audit Context

Lifecycle mutation commands should allow limited optional Host-provided auditing context.

Recommended fields:

```text
actorKey
reason
correlationKey
```

These values are opaque.

The package must not know:

- user tables,
- authentication models,
- application actors,
- request objects.

The purpose is only to preserve useful lifecycle provenance.

---

# 33. Exception Model

The package should expose a stable semantic exception taxonomy.

Possible exceptions include:

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

Package exceptions must follow the Maatify shared exception hierarchy.

Unknown infrastructure failures should not be blindly converted.

Database duplicate-key conversion must occur only when the driver-specific evidence proves the semantic conflict.

Unknown `PDOException` instances may propagate unchanged.

---

# 34. Clock and Time

All lifecycle timestamps should use the shared Maatify clock abstraction.

The package must use:

```text
Maatify\SharedCommon\Contracts\ClockInterface
```

The package must not mutate global PHP timezone configuration.

The Host owns application timezone configuration.

---

# 35. Public Domain Operations

The final names should be decided during Blueprint design, but the domain surface should cover operations equivalent to the following.

## Generation / Normalization

```text
generate
normalize
validate
encodeSegment
decodeSegment
```

## Availability / Allocation

```text
checkAvailability
claimExact
allocate
```

## Lifecycle

```text
assign
change
restore
deactivate
reactivate
purge
```

## Alias

```text
addAlias
removeAlias
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

The final API should avoid generic CRUD terminology where domain-specific operations provide clearer guarantees.

---

# 36. Proposed Domain Organization

The package should organize by domain/capability rather than by generic technical folders.

Conceptual organization:

```text
src/
    Profile/
    Generation/
    Segment/
    Scope/
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

This is a logical map, not yet a locked filesystem blueprint.

Empty ceremonial directories must not be created only to match this diagram.

---

# 37. Required Runtime Dependencies

The final dependency graph should remain minimal and explicit.

Expected shared dependencies where applicable:

```text
maatify/exceptions
maatify/shared-common
maatify/persistence
```

Usage:

- `maatify/exceptions` for package-owned exceptions.
- `maatify/shared-common` for `ClockInterface`.
- `maatify/persistence` for shared pagination capabilities where management lists require pagination.

The package must not duplicate stable shared Maatify capabilities.

---

# 38. Testing and RC Readiness

The first RC should not be considered complete based only on unit tests or PHPStan.

The package requires:

- Unit tests.
- Real MySQL/MariaDB integration tests where supported by the package contract.
- Public API / system-level workflow tests.
- Consumer Verification Harness.
- Concurrency tests.
- Transaction and savepoint verification.
- Clean consumer installation verification.
- PHPStan level max.
- Composer validation.
- Production autoload verification.
- Lowest supported dependency verification where applicable.

Important scenarios include:

```text
English generation
Arabic generation
Unicode generation
ASCII transliteration
Unicode normalization equivalence
normalization idempotence
invalid UTF-8
dangerous controls
reserved slugs
exact claims
automatic allocation
collision suffixing
maximum-length suffixing
same slug in different scopes
same slug in same scope
same entity restore
cross-entity historical reuse prevention
alias creation
alias removal
alias promotion
historical resolution
current resolution
alias resolution
inactive resolution
canonical request
non-canonical request
legacy current adoption
legacy history adoption
legacy alias adoption
concurrent exact claim
concurrent auto allocation
concurrent same-binding update
revision conflict
owned transaction rollback
caller-owned transaction
savepoint rollback
outer transaction rollback
destructive purge
reuse after explicit purge
management pagination
consumer installation
```

Race-prone invariants must be tested with real concurrent access rather than only mocked repositories.

---

# 39. RC Scope Summary

The first RC should include the following complete capability areas:

| Domain | RC Scope |
|---|---|
| Profile | versioned generation/normalization profiles |
| Generation | source → candidate |
| Normalization | canonical slug identity |
| Segment | validation + URL segment codec |
| Scope | namespace + locale + context + profile |
| Identity | Host-provided opaque entity reference |
| Allocation | exact claim + auto allocation |
| Availability | advisory availability classification |
| Lifecycle | assign/change/restore/deactivate/reactivate/purge |
| Alias | add/remove/promote |
| Resolution | current/alias/history/inactive lookup |
| History | immutable lifecycle timeline |
| Adoption | legacy current/history/alias adoption |
| Management | search/audit/pagination APIs |
| Persistence | authoritative PDO-backed storage |
| Transactions | owned transaction + caller savepoint support |
| Concurrency | slug collisions + same-entity revision control |
| Exceptions | stable semantic taxonomy |
| Clock | shared ClockInterface |
| Testing | unit + integration + system + consumer + concurrency |

These are considered part of the **first RC foundation**, not postponed roadmap features.

---

# 40. Explicit Non-Goals

The following are intentionally outside the package rather than deferred:

```text
Router
Route definitions
Full URL builder
HTTP redirect policy
SEO redirect persistence
Canonical tags
hreflang
Framework middleware
Laravel integration as core dependency
Symfony integration as core dependency
Slim integration as core dependency
ORM listeners
Host model traits
Automatic title/name monitoring
Host entity existence checks
Host database joins
Host foreign keys
Authentication
Authorization
Admin UI
SEO policy
Application bootstrap
```

Framework or application adapters may exist later as separate integrations if there is a real need.

---

# 41. Core Architectural Invariants to Lock Before Blueprint

The following decisions should be treated as the main architectural foundation unless discussion identifies a concrete flaw:

1. The package is an authoritative Slug Lifecycle Engine, not only a slug generator.
2. Stateless generation remains usable without persistence.
3. Persistent lifecycle is fully owned by the package when the Host chooses to use it.
4. Slugs represent one URL path segment only.
5. Entity identity is Host-provided and opaque.
6. No Host FK or JOIN is allowed.
7. Scope is first-class.
8. Scope supports namespace, locale, context, and a versioned profile.
9. Current, historical, and alias slugs share one ownership registry.
10. Slug ownership survives normal lifecycle changes.
11. Cross-entity reuse of historical slugs is forbidden unless explicit purge occurs.
12. The same entity can restore a historical slug.
13. Exact claim and automatic allocation are different operations.
14. Database uniqueness is authoritative.
15. Availability checks are advisory.
16. Same-slug concurrency is protected by database uniqueness.
17. Same-entity concurrency is protected by revision control and locking.
18. Lifecycle mutations are atomic.
19. Caller-owned transactions are respected.
20. Savepoints protect package atomicity inside caller transactions.
21. History is immutable.
22. Aliases are first-class and different from history.
23. Resolution returns semantic state instead of HTTP behavior.
24. Historical resolution points directly to the current canonical state.
25. Canonical/non-canonical requests are distinguishable.
26. Generation/normalization behavior is versioned by profile.
27. Unicode is first-class.
28. Arabic must work without forced ASCII conversion.
29. ASCII transliteration is available as a separate profile.
30. Reserved slugs are supported.
31. URL segment validation and codec behavior are explicit.
32. Destructive purge is explicit and separate from deactivation.
33. Legacy adoption is supported from the first RC.
34. The package exposes management/query APIs for owned data.
35. Clock uses `ClockInterface`.
36. Shared pagination uses the Maatify persistence package.
37. Package exceptions use the Maatify exception hierarchy.
38. Unknown infrastructure failures are not blindly wrapped.
39. The Slug package has no dependency on SEO.
40. SEO has no dependency on Slug.
41. Host/adapter code owns integrations between Slug and other domains.

---

# 42. Working Definition

A concise package definition for discussion:

> **Maatify Slug provides deterministic slug generation, normalization, allocation, ownership, lifecycle, aliases, history, resolution, migration, and concurrency-safe persistence for host-agnostic PHP applications.**

---

# 43. Discussion Status

This document is **not yet the formal Blueprint**.

It is the working architectural concept that should be debated and revised before implementation planning.

Topics to discuss next may include:

- final naming,
- exact table schema,
- exact status/event enums,
- exact profile architecture,
- exact public API classes and commands,
- transaction/savepoint implementation contract,
- purge policy,
- alias semantics,
- resolution result contract,
- migration/adoption rules,
- supported database driver policy,
- package dependency constraints,
- release-phase breakdown.

Once these decisions are stable, this document can be used as the source for the formal package Blueprint.
