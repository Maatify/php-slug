# Maatify Slug — Package Reference

> **Canonical root Package Reference** for `maatify/php-slug`.
>
> **Current status:** RC1 implementation is complete. This file records the implemented contract and package boundaries available in the Runtime, source schema, tests, and CI configuration. The package is not published on Packagist; no Stable release or SemVer Release Candidate is publicly available.

## 1. Package Identity and Status

| Field | Current value |
|---|---|
| Composer package name | `maatify/php-slug` |
| Repository | `Maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| Artifact | Standalone reusable PHP/Composer library |
| Host model | Host-agnostic |
| Current workflow state | Development / Unpublished |
| Published Stable line | None |
| Published SemVer RC | None |

This repository contains the implementation (Runtime, Tests, Schema, and CI), but no public `composer require` installation command is available before official publication.

The durable references associated with this document are:

- [`docs/php-engineering-standards/STANDARDS_MANIFEST.md`](docs/php-engineering-standards/STANDARDS_MANIFEST.md) — the Local Resolver Record for the applicable Standards.
- [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) — consumer usage and integration guidance.
- [`examples/`](examples/) — runnable consumer examples.

## 2. Purpose and Ownership Boundaries

RC1 is an **Authoritative Slug Lifecycle Engine**, not only a text-generation helper. The package owns generation, canonicalization, scoped ownership, allocation, lifecycle, aliases, history, resolution, adoption, and package-owned persistence, with defined concurrency, transaction, result, and exception contracts.

The package owns:

- slug generation, canonicalization, and lookup semantics;
- versioned profiles and the reserved-slug policy supplied by the Host;
- `SlugScope` and `EntityReference` as stable domain identity;
- current ownership, historical canonical ownership, and active or retired aliases;
- exact claiming, bounded generated allocation, and advisory availability;
- lifecycle transitions, release, purge, scope transition, and atomic transfer;
- immutable normal-lifecycle history, adoption, and management queries; and
- package-owned persistence when the persisted path is used.

The Host owns:

- connection setup, bootstrap, `PDO` configuration, and Host entity existence and lifecycle;
- `EntityReference` identity and validation of entity existence;
- reserved-slug vocabulary and patterns;
- routing, full path and URL construction, and percent encoding or decoding;
- HTTP controllers, middleware, status policy, and redirects;
- SEO records, canonical tags, hreflang, and any Slug-to-SEO integration; and
- authentication, authorization, Admin UI, and application bootstrap.

The package has no Host foreign keys, Host table joins, or Host repository dependencies, and does not depend on a framework, ORM, or `maatify/php-seo`.

## 3. Supported State and Installation

The package is available as source code with a complete Runtime and `composer.json`, but it is **not published**. The currently supported access path is cloning the repository and reading this reference. Do not use `composer require maatify/php-slug` from public sources before official publication.

The Runtime provides implemented examples. The current stateless construction path is:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$profiles->register($customProfile); // optional before creating services
$text = SlugTextServiceFactory::create($profiles);
```

The persisted construction path is:

```php
$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
```

These are supported Runtime paths. No Factory creates a hidden connection, reads `.env`, or acts as a Service Locator.

### 3.1 Source Topology

**Source Topology: Multi Capability**

Capabilities:

- Canonicalization
- Lifecycle

Dependency:

`Lifecycle → Canonicalization`

Package-wide responsibilities:

- Exception
- Factory
- Facade

Consumer and Management use cases remain inside `Lifecycle`; Persistence is not a separate Capability or architecture root.

## 4. Runtime and Platform Contract for RC1

The following requirements are implemented in the repository:

| Requirement | Value |
|---|---|
| PHP | `^8.4`; the intended matrix is PHP 8.4 and 8.5 without a PHP ceiling |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| ICU | Major `74` and Unicode data `15.1` for the built-in profiles |
| Database adapter | PDO MySQL with MySQL-compatible semantics |
| MySQL | Server `8.0.36` as the CI reproducibility target |
| Runtime packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |
| Evidence tools | `phpstan/phpstan ^2.1`, `phpunit/phpunit ^11.5`, `friendsofphp/php-cs-fixer ^3.94` in `require-dev` |

The implementation uses `ext-intl` for NFC and ICU lowercasing or transliteration and uses `ext-mbstring` only for code-point length. An ICU or Unicode tuple mismatch fails closed with `SlugRuntimeCompatibilityException` before any mutation; PHP `^8.4` alone does not prove support for another ICU tuple.

## 5. Identity and Text

### 5.1 `Slug` and `Profile`

`Slug` is a decoded canonical URL path-segment token, not a full path, URL, or percent-encoded text. It has no public constructor; the only construction path is:

```php
Slug::fromProfile(SlugProfileInterface $profile, string $canonicalValue): Slug
```

This invokes `assertCanonicalSlug()` without conversion. There are no `fromRaw`, `fromTrusted`, or hydration bypass paths.

The built-in keys are only `unicode-v1` and `ascii-v1`. Custom profiles use a versioned key matching:

```text
^(?=.{1,63}$)[a-z][a-z0-9-]*-v[1-9][0-9]*$
```

A built-in key cannot be replaced and duplicate registration is rejected with `SlugProfileAlreadyRegisteredException`.

### 5.2 The Three Operations

| Operation | Meaning | Core rule |
|---|---|---|
| `generateFromSource` | Human source text | Allows the lossy transformations declared by the Profile |
| `canonicalizeClaim` | Exact candidate | Applies only NFC, lowercase, and declared equivalences; no source cleanup |
| `canonicalizeLookup` | Decoded URL segment | Uses the narrowest canonicalization; no generation-only transformations |

Each canonicalization operation is idempotent within its scope. `hello!!!` is rejected during lookup and is not converted to `hello`.

### 5.3 Profiles, Safety, and Length

`unicode-v1` uses NFC and ICU `Any-Lower`, preserves Arabic, and permits canonical code points from `L/M/Nd` plus the ASCII hyphen. `ascii-v1` uses NFC followed by ICU `Any-Latin; Latin-ASCII` and ASCII lowercase. Locked examples are `آيفون ١٧ برو` → `آيفون-١٧-برو` and `Über Café` → `uber-cafe`.

Before any lossy transformation, invalid UTF-8, NUL, `Cc`, `Cs`, `Cf`, `/`, `\\`, and an empty result are rejected. Mixed scripts are allowed in `unicode-v1` when the remaining rules are satisfied.

The maximum is 160 Unicode code points after canonicalization. An exact claim or lookup exceeding the limit is rejected. Generated allocation uses the base and then only `-2` through `-1000`; it accounts for the suffix before code-point truncation, removes a trailing hyphen, and raises `SlugAllocationExhaustedException` when exhausted.

`namespace` matches `^[a-z][a-z0-9-]{0,62}$`. `localeKey`, `contextKey`, `entityType`, and `entityKey` are opaque, valid UTF-8, and exact, without trimming, normalization, or case folding. A `null` locale or context is the exact empty dimension only; it is not a wildcard or fallback. `profileKey` is immutable Scope configuration and is not a uniqueness dimension.

## 6. Public API Inventory

All public types are under `Maatify\\Slug\\`. Every interface ends with `Interface`, every enum with `Enum`, every DTO, Command, and Criteria is `final readonly`, and every DTO implements `JsonSerializable`. Repositories, SQL builders, and lock coordinators are not Public API.

### 6.1 Interfaces and Operations

```text
SlugTextServiceInterface
  generateFromSource(SlugProfileKey, string): GeneratedSlugDTO
  canonicalizeClaim(SlugProfileKey, string): CanonicalSlugDTO
  canonicalizeLookup(SlugProfileKey, string): LookupCanonicalizationDTO

SlugProfileInterface
  key(): SlugProfileKey
  generateFromSource(string): GeneratedSlugDTO
  canonicalizeClaim(string): CanonicalSlugDTO
  canonicalizeLookup(string): LookupCanonicalizationDTO
  assertCanonicalSlug(string): void

SlugProfileRegistryInterface
  register(SlugProfileInterface): void
  get(SlugProfileKey): SlugProfileInterface
  has(SlugProfileKey): bool

ReservedSlugPolicyInterface
  isReserved(SlugScope, Slug): bool

SlugScopeRegistryInterface
  ensureScope(ScopeProfileRequestDTO): ScopeDTO

SlugLifecycleServiceInterface
  assignExact(AssignExactCommand): SlugMutationResultDTO
  assignGenerated(AssignGeneratedCommand): SlugMutationResultDTO
  changeExact(ChangeExactCommand): SlugMutationResultDTO
  changeGenerated(ChangeGeneratedCommand): SlugMutationResultDTO
  restoreHistorical(RestoreHistoricalCommand): SlugMutationResultDTO
  deactivate(DeactivateBindingCommand): SlugMutationResultDTO
  reactivate(ReactivateBindingCommand): SlugMutationResultDTO
  releaseClaim(ReleaseClaimCommand): SlugMutationResultDTO
  releaseAllOwnership(ReleaseAllOwnershipCommand): SlugMutationResultDTO
  transitionScope(TransitionScopeCommand): ScopeTransitionResultDTO
  atomicTransfer(AtomicTransferCommand): AtomicTransferResultDTO
  addAlias(AddAliasCommand): SlugMutationResultDTO
  retireAlias(RetireAliasCommand): SlugMutationResultDTO
  reactivateAlias(ReactivateAliasCommand): SlugMutationResultDTO
  promoteAliasToCurrent(PromoteAliasToCurrentCommand): SlugMutationResultDTO
  adoptCurrent(AdoptCurrentCommand): AdoptionResultDTO
  adoptHistorical(AdoptHistoricalCommand): AdoptionResultDTO
  adoptAlias(AdoptAliasCommand): AdoptionResultDTO
  purgeBinding(PurgeBindingCommand): void

SlugQueryServiceInterface
  checkAvailability(AvailabilityCriteria): SlugAvailabilityDTO
  getCurrent(CurrentSlugCriteria): ?CurrentSlugDTO
  resolve(ResolutionCriteria): SlugResolutionDTO

SlugManagementQueryInterface
  getBinding(BindingCriteria): ?BindingDTO
  getCurrent(CurrentSlugCriteria): ?CurrentSlugDTO
  listAliases(AliasCriteria): PageResult<AliasDTO>
  getHistory(HistoryCriteria): PageResult<HistoryEventDTO>
  inspectRegistry(RegistryCriteria): PageResult<RegistryClaimDTO>
  inspectScope(ScopeCriteria): ?ScopeDTO
  searchBindings(BindingSearchCriteria): PageResult<BindingDTO>
  searchRegistry(RegistrySearchCriteria): PageResult<RegistryClaimDTO>
```

`SlugProfileRegistryFactory::createBuiltIn()` and `SlugTextServiceFactory::create(SlugProfileRegistryInterface)` are stateless paths. `SlugEngineFactory::create(PDO, SlugProfileRegistryInterface, ReservedSlugPolicyInterface, ClockInterface)` returns the final `SlugEngine` for the persisted path; it does not add another `SlugEngine` contract.

### 6.2 Commands and Criteria

Every mutation method accepts one Command rather than a raw parameter list. The complete Command index is:

```text
AssignExactCommand, AssignGeneratedCommand,
ChangeExactCommand, ChangeGeneratedCommand, RestoreHistoricalCommand,
DeactivateBindingCommand, ReactivateBindingCommand,
ReleaseClaimCommand, ReleaseAllOwnershipCommand,
TransitionScopeCommand, AtomicTransferCommand,
AddAliasCommand, RetireAliasCommand, ReactivateAliasCommand,
PromoteAliasToCurrentCommand,
AdoptCurrentCommand, AdoptHistoricalCommand, AdoptAliasCommand,
PurgeBindingCommand
```

Commands use `BindingIdentityDTO` or `ScopeProfileRequestDTO` and, where applicable, `AuditContextDTO`, `expectedRevision`, `ScopeTransitionClaimIntentDTO`, `TransferReplacementIntentDTO`, and `originalOccurredAt`. `expectedRevision = null` asserts that the Binding is absent; every existing Binding, including `RELEASED`, requires its current revision.

The locked public constructors are:

```text
AssignExactCommand(BindingIdentityDTO, string slugCandidate, ?int expectedRevision, AuditContextDTO)
AssignGeneratedCommand(BindingIdentityDTO, string sourceText, ?int expectedRevision, AuditContextDTO)
ChangeExactCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
ChangeGeneratedCommand(BindingIdentityDTO, string sourceText, int expectedRevision, AuditContextDTO)
RestoreHistoricalCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
DeactivateBindingCommand(BindingIdentityDTO, int expectedRevision, AuditContextDTO)
ReactivateBindingCommand(BindingIdentityDTO, int expectedRevision, AuditContextDTO)
ReleaseClaimCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
ReleaseAllOwnershipCommand(BindingIdentityDTO, int expectedRevision, AuditContextDTO)
TransitionScopeCommand(BindingIdentityDTO source, ScopeProfileRequestDTO targetScope, ScopeTransitionModeEnum mode, ScopeTransitionClaimIntentDTO targetClaimIntent, int sourceExpectedRevision, AuditContextDTO audit)
AtomicTransferCommand(BindingIdentityDTO source, BindingIdentityDTO target, string slugCandidate, ?TransferReplacementIntentDTO sourceReplacementIntent, int sourceExpectedRevision, int targetExpectedRevision, AuditContextDTO audit)
AddAliasCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
RetireAliasCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
ReactivateAliasCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
PromoteAliasToCurrentCommand(BindingIdentityDTO, string slugCandidate, int expectedRevision, AuditContextDTO)
AdoptCurrentCommand(BindingIdentityDTO, string slugCandidate, ?DateTimeImmutable originalOccurredAt, ?int expectedRevision, AuditContextDTO)
AdoptHistoricalCommand(BindingIdentityDTO, string slugCandidate, ?DateTimeImmutable originalOccurredAt, int expectedRevision, AuditContextDTO)
AdoptAliasCommand(BindingIdentityDTO, string slugCandidate, ?DateTimeImmutable originalOccurredAt, int expectedRevision, AuditContextDTO)
PurgeBindingCommand(BindingIdentityDTO, int expectedRevision)
```

Every Command is `final readonly` and validates only its input contract; it does not perform orchestration or persistence. `PurgeBindingCommand` alone has no `AuditContextDTO` or idempotency contract.

The complete Criteria index is:

```text
AvailabilityCriteria, ResolutionCriteria, BindingCriteria,
CurrentSlugCriteria, AliasCriteria, HistoryCriteria, RegistryCriteria,
ScopeCriteria, BindingSearchCriteria, RegistrySearchCriteria
```

Criteria use the published `Maatify\\Persistence\\Pdo\\Pagination\\PageRequest` as the only pagination and sorting input where required. There are no local `PageRequest`, `PageResult`, `SortDirectionEnum`, or `*PageDTO` types.

The locked Criteria constructors are:

```text
AvailabilityCriteria(ScopeProfileRequestDTO, string candidate, ?BindingIdentityDTO requestingBinding = null)
ResolutionCriteria(ScopeProfileRequestDTO, string decodedSegment)
BindingCriteria(BindingIdentityDTO)
CurrentSlugCriteria(BindingIdentityDTO)
AliasCriteria(BindingIdentityDTO, PageRequest pageRequest)
HistoryCriteria(BindingIdentityDTO, PageRequest pageRequest, ?HistoryEventTypeEnum eventType = null)
RegistryCriteria(ScopeProfileRequestDTO, PageRequest pageRequest, ?BindingIdentityDTO binding = null, ?RegistryRoleEnum role = null)
ScopeCriteria(ScopeProfileRequestDTO)
BindingSearchCriteria(ScopeProfileRequestDTO, PageRequest pageRequest, ?string entityType = null, ?string entityKeyPrefix = null, ?BindingStatusEnum status = null)
RegistrySearchCriteria(ScopeProfileRequestDTO, PageRequest pageRequest, ?string slugPrefix = null, ?RegistryRoleEnum role = null, ?BindingStatusEnum bindingStatus = null)
```

Criteria provide query validation only; they do not redefine result DTOs or pagination mechanics.

### 6.3 DTOs and Enums

The public DTOs are:

```text
GeneratedSlugDTO, CanonicalSlugDTO, LookupCanonicalizationDTO,
ScopeProfileRequestDTO, BindingIdentityDTO,
ScopeTransitionClaimIntentDTO, TransferReplacementIntentDTO,
ScopeDTO, BindingStateDTO, BindingDTO, RegistryClaimDTO, CurrentSlugDTO,
AliasDTO, HistoryEventDTO, AuditContextDTO,
SlugAvailabilityDTO, SlugResolutionDTO, SlugMutationResultDTO,
BindingStateResultDTO, ScopeTransitionResultDTO,
AtomicTransferResultDTO, AdoptionResultDTO
```

Locked enum values are:

```text
BindingStatusEnum: ACTIVE, INACTIVE, RELEASED
RegistryRoleEnum: CURRENT_CANONICAL, HISTORICAL_CANONICAL, ACTIVE_ALIAS, RETIRED_ALIAS
MatchKindEnum: CURRENT, ALIAS, HISTORICAL, RETIRED_ALIAS, NONE
InputFormCanonicalityEnum: CANONICAL, NON_CANONICAL, INVALID, NOT_APPLICABLE
AvailabilityStatusEnum: AVAILABLE, OWNED_BY_SAME_BINDING, OWNED_BY_OTHER_BINDING, RESERVED, INVALID
ScopeTransitionModeEnum: MOVE, PARALLEL
ClaimIntentModeEnum: EXACT, GENERATED
ChangeTypeEnum: ASSIGNED, CHANGED, RESTORED, DEACTIVATED, REACTIVATED, RELEASED, RELEASED_ALL, ALIAS_ADDED, ALIAS_RETIRED, ALIAS_REACTIVATED, ALIAS_PROMOTED
OperationTypeEnum: ASSIGN_EXACT, ASSIGN_GENERATED, CHANGE_EXACT, CHANGE_GENERATED, RESTORE_HISTORICAL, DEACTIVATE, REACTIVATE, RELEASE_CLAIM, RELEASE_ALL, TRANSITION_SCOPE, ATOMIC_TRANSFER, ADD_ALIAS, RETIRE_ALIAS, REACTIVATE_ALIAS, PROMOTE_ALIAS, ADOPT_CURRENT, ADOPT_HISTORICAL, ADOPT_ALIAS
HistoryEventTypeEnum: ASSIGNED, CHANGED, RESTORED, ALIAS_ADDED, ALIAS_RETIRED, ALIAS_REACTIVATED, ALIAS_PROMOTED, DEACTIVATED, REACTIVATED, SCOPE_TRANSITIONED_OUT, SCOPE_TRANSITIONED_IN, OWNERSHIP_RELEASED, OWNERSHIP_RELEASED_ALL, OWNERSHIP_TRANSFERRED_OUT, OWNERSHIP_TRANSFERRED_IN, ADOPTED_CURRENT, ADOPTED_HISTORICAL, ADOPTED_ALIAS
```

Result DTOs carry before and after state, revisions, claims, and history according to the operation. `SlugResolutionDTO` distinguishes match kind from Binding status and input canonicality. `SlugAvailabilityDTO` is always advisory and never becomes a claim guarantee.

The locked public DTO fields are:

```text
GeneratedSlugDTO: SlugProfileKey $profileKey, string $source, Slug $slug
CanonicalSlugDTO: SlugProfileKey $profileKey, string $input, Slug $slug
LookupCanonicalizationDTO: SlugProfileKey $profileKey, string $decodedSegment, InputFormCanonicalityEnum $canonicality, ?Slug $canonicalSlug
ScopeDTO: int $id, SlugScope $scope, SlugProfileKey $profileKey, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt
BindingStateDTO: BindingStatusEnum $status, ?Slug $currentSlug, int $revision, int $historySequence
BindingDTO: int $id, BindingIdentityDTO $identity, BindingStateDTO $state, ?RegistryClaimDTO $currentClaim, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt
RegistryClaimDTO: int $id, BindingIdentityDTO $binding, Slug $slug, RegistryRoleEnum $role, DateTimeImmutable $claimedAt, DateTimeImmutable $updatedAt
CurrentSlugDTO: BindingDTO $binding, RegistryClaimDTO $claim, int $revision
AliasDTO: RegistryClaimDTO $claim, bool $resolvableAsAlias, int $bindingRevision
SlugAvailabilityDTO: ScopeProfileRequestDTO $scopeProfile, string $requestedInput, ?Slug $canonicalSlug, AvailabilityStatusEnum $status, ?BindingDTO $owner, bool $advisory
SlugResolutionDTO: ScopeProfileRequestDTO $scopeProfile, string $requestedSegment, InputFormCanonicalityEnum $inputCanonicality, ?Slug $lookupCanonicalSlug, ?Slug $matchedSlug, MatchKindEnum $matchKind, ?BindingStatusEnum $bindingStatus, ?Slug $currentSlug, ?EntityReference $entity, ?int $bindingRevision
SlugMutationResultDTO: OperationTypeEnum $operationType, ?string $operationKey, bool $replayed, ?BindingDTO $before, BindingDTO $after, list<RegistryClaimDTO> $affectedClaims, ?Slug $previousSlug, ?Slug $currentSlug, ChangeTypeEnum $changeType, int $revision, list<HistoryEventDTO> $historyEvents
BindingStateResultDTO: ?BindingDTO $before, BindingDTO $after, bool $mutated, int $revision, list<HistoryEventDTO> $historyEvents
ScopeTransitionResultDTO: OperationTypeEnum $operationType, ?string $operationKey, bool $replayed, ScopeTransitionModeEnum $mode, bool $targetCreated, BindingStateResultDTO $sourceResult, BindingStateResultDTO $targetResult, ?BindingDTO $sourceBefore, BindingDTO $sourceAfter, ?BindingDTO $targetBefore, BindingDTO $targetAfter, ?Slug $sourceClaim, ?Slug $targetClaim, int $sourceRevision, int $targetRevision, list<HistoryEventDTO> $historyEvents
AtomicTransferResultDTO: OperationTypeEnum $operationType, ?string $operationKey, bool $replayed, BindingStateResultDTO $sourceResult, BindingStateResultDTO $targetResult, RegistryClaimDTO $transferredClaim, ?SlugMutationResultDTO $sourceReplacementResult, int $sourceRevision, int $targetRevision, list<HistoryEventDTO> $historyEvents
AdoptionResultDTO: OperationTypeEnum $operationType, ?string $operationKey, bool $replayed, ?BindingDTO $before, BindingDTO $after, RegistryClaimDTO $adoptedClaim, HistoryEventDTO $historyEvent
```

`ScopeProfileRequestDTO` carries `SlugScope $scope` and `SlugProfileKey $expectedProfileKey`; `BindingIdentityDTO` carries `ScopeProfileRequestDTO $scopeProfile` and `EntityReference $entity`. Intent DTOs carry `ClaimIntentModeEnum $mode` and `string $value`. `AuditContextDTO` has optional `?string $actorKey`, `?string $reason`, `?string $correlationKey`, and `?string $idempotencyKey`. `HistoryEventDTO` and its role-snapshot fields follow the applicability defined by the current lifecycle implementation.

## 7. Ownership and Lifecycle

The live claim identity is `(Scope, canonical Slug)`. `EntityReference` is `(entityType, entityKey)` supplied by the Host; the package does not validate entity existence or reinterpret that identity.

### 7.1 Registry and Binding

| Concept | Contract |
|---|---|
| Scope identity | `namespace + localeKey + contextKey`; `profileKey` is not part of uniqueness |
| Binding states | `ACTIVE`, `INACTIVE`, `RELEASED` |
| Live roles | `CURRENT_CANONICAL`, `HISTORICAL_CANONICAL`, `ACTIVE_ALIAS`, `RETIRED_ALIAS` |
| ACTIVE/INACTIVE | Must have a current pointer and a valid Registry claim |
| RELEASED | Has no Registry claims and no current pointer |
| History | Immutable normal-lifecycle snapshots; does not depend on Registry retention |

An ordinary change preserves the previous canonical as historical and does not release ownership. Retired aliases remain reserved. `releaseClaim` releases only a non-current claim, and `releaseAllOwnership` clears all claims and places the Binding in `RELEASED`. `purgeBinding` is destructive and is allowed only for `RELEASED` Bindings with no claims; after success, replay guarantees for that Binding end.

### 7.2 Composite Operations

- `transitionScope` creates a new target Binding after target-profile canonicalization. `MOVE` makes the source `INACTIVE`, while `PARALLEL` leaves the source unchanged; it does not rewrite `scope_id`.
- `atomicTransfer` is same-Scope only and requires a target Binding that exists before the operation. It moves a claim in one transaction; transferring a current claim requires a different replacement, and the target preserves the original role exactly. RC1 does not support cross-scope transfer.
- `adoptCurrent` permits an absent or `RELEASED` Binding according to `expectedRevision`, while `adoptHistorical` and `adoptAlias` require an `ACTIVE` or `INACTIVE` Binding with a current claim.
- `originalOccurredAt` in adoption is a `DateTimeImmutable` in any timezone, converted to UTC with six microseconds preserved. It is rejected only outside the MySQL `DATETIME(6)` range; it is not compared with the Clock for future or past validation.

## 8. Replay and Result Snapshot

Idempotency is optional. Without a key, a mutation has no operation or participant evidence, `operationKey = null`, and `replayed = false`. With a key, operation evidence, a request fingerprint SHA-256, and an immutable Result Snapshot are stored.

Result Snapshot JSON v1 has only four discriminators:

| `result_type` | Aggregate |
|---|---|
| `mutation` | `SlugMutationResultDTO` for single operations only |
| `transition` | `ScopeTransitionResultDTO` |
| `transfer` | `AtomicTransferResultDTO` |
| `adoption` | `AdoptionResultDTO` |

`result_schema_version` is JSON integer `1`, and the top-level key order is `result_type`, then `result_schema_version`, then `result`. The snapshot is compact UTF-8 and immutable; it is not a dump of live state. Replay reads the committed snapshot and returns the same DTO with `replayed = true` in memory only. It does not rebuild the result from the current Registry or create new History or revisions. Any metadata, schema, or nested-shape mismatch raises `SlugPersistenceInvariantException`.

Only a current `atomicTransfer` result may contain nested `sourceReplacementResult`; its `operationKey` and `replayed` values equal those of the outer result. A top-level `mutation` cannot use `ATOMIC_TRANSFER`.

## 9. Persistence, Transactions, and Concurrency

The persisted path uses direct PDO inside package infrastructure only:

- `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION` and `PDO::ATTR_EMULATE_PREPARES = false`.
- Connection charset is `utf8mb4`; timestamps come from `ClockInterface` as UTC `DATETIME(6)`. Database defaults are not a behavioral source.
- There is no ORM, external query builder, or SQLite, MariaDB, or PostgreSQL fallback.
- The package owns a transaction when no outer transaction exists; it never commits or rolls back a caller-owned transaction.
- Nested participation uses a savepoint when the capability is supported; otherwise execution fails before mutation with `SlugTransactionParticipationException`.
- Unique database constraints are the final claim authority. A duplicate is converted semantically only for MySQL `errorInfo[1] === 1062` with known constraint context; other infrastructure throwables are rethrown, retaining `previous` when wrapping.
- First-create races on Binding and Scope are reread under lock in the defined paths. There is no savepoint for an attempted `INSERT` and no automatic deadlock retry.
- `revision` protects same-Binding CAS, and multi-row lifecycle and History mutations are atomic. Lock ordering in transfer and transition is part of the execution contract.

On failure or invariant violation, partial mutation, uncommitted History, and uncommitted operations must be removed by the operation rollback. The original throwable is rethrown unless a documented semantic conversion applies.

## 10. Management, Resolution, and Pagination

`resolve` reads a decoded segment and returns `SlugResolutionDTO` even for `NONE` and `INVALID`. `MatchKindEnum` distinguishes `CURRENT`, `ALIAS`, `HISTORICAL`, `RETIRED_ALIAS`, and `NONE`, and does not return live resolution for `RELEASED`. `INACTIVE` remains matchable; the Host owns the 404, 410, or redirect decision.

Management queries separate the live Registry from History:

```text
listAliases, getHistory, inspectRegistry, searchBindings, searchRegistry
```

Every unbounded list or search uses `PageResult` from `maatify/persistence ^1.1`. Slug owns filters, selected columns, count/data predicate alignment, and row mapping; `PdoPaginator` owns shared mechanics. Current package-specific values are:

```text
defaultPerPage = 25
minPerPage = 1
maxPerPage = 100
tie-breaker = id ASC
```

There are no local pagination DTOs, enums, or paginator, and the Host does not interpret these contracts through generic filters or an Admin UI.

### 10.1 Operational Read / Reporting

```text
Classification: IN SCOPE
```

The package owns meaningful persisted Slug state, including:

- `Scopes`;
- `Bindings`;
- Registry claims;
- History; and
- lifecycle states and `revisions`.

Therefore the package does not classify Operational Read / Reporting as out of scope, and the Host does not need to query package tables directly to reconstruct these meanings. The stable public operational-read contract is `SlugManagementQueryInterface`, which is read-only. Management and Consumer APIs have separate contracts; exposing both through `SlugEngine` does not change that separation.

The current contracts and semantics are:

```text
getBinding(BindingCriteria)
→ exact Binding identity; returns BindingDTO or null

getCurrent(CurrentSlugCriteria)
→ current canonical slug read; returns CurrentSlugDTO or null

listAliases(AliasCriteria)
→ aliases for one Binding with pagination

getHistory(HistoryCriteria)
→ History for one Binding with optional HistoryEventTypeEnum and pagination

inspectRegistry(RegistryCriteria)
→ Registry for one Scope with optional Binding, optional RegistryRoleEnum, and pagination

inspectScope(ScopeCriteria)
→ Scope matching ScopeProfileRequestDTO; returns ScopeDTO or null

searchBindings(BindingSearchCriteria)
→ Scope with optional entityType, entityKeyPrefix, BindingStatusEnum, and pagination

searchRegistry(RegistrySearchCriteria)
→ Scope with optional slugPrefix, RegistryRoleEnum, BindingStatusEnum, and pagination
```

These Management APIs do not mutate state and are not an alternate mutation path. The package owns the meanings of Slug scopes, Bindings, claims, History, status, and revision; the Host owns presentation, permissions, HTTP, exports, entity meaning and names, and cross-package aggregation.

RC1 does not currently expose public reporting contracts for:

```text
generic dashboard
aggregate/count/grouped metrics
time-window reporting filters
CSV/PDF/Excel exports
Host actor/name resolution
cross-package reporting
```

The package does not add APIs for these dimensions merely to satisfy a Reporting standard. Internal operation or idempotency rows, although persisted, are not public reporting contracts.

## 11. Technical Consumer Workflow

The normative RC1 consumer workflow is one connected path:

```text
Host Input
→ Public API
→ Domain Service
→ Integration Boundary
→ Observable Result
```

### 11.1 Host Input and Construction

The Host supplies these inputs and contracts:

```text
PDO matching the Runtime contract
SlugProfileRegistryInterface
ReservedSlugPolicyInterface
ClockInterface

BindingIdentityDTO
slug candidate
expectedRevision
AuditContextDTO
```

Built-in profiles are configured through the supported public path, then the stateful engine is created through the Factory:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();

$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
```

```text
SlugEngineFactory::create(
    PDO,
    SlugProfileRegistryInterface,
    ReservedSlugPolicyInterface,
    ClockInterface
)
→ SlugEngine
```

The Factory does not create a hidden connection or read Host configuration or `.env` automatically.

### 11.2 Public API and Domain Path

The consumer uses `assignExact` through one Command:

```php
$result = $engine->assignExact(
    new AssignExactCommand(
        $binding,
        $slugCandidate,
        $expectedRevision,
        $audit,
    ),
);
```

The actual domain path is:

```text
SlugEngine
→ PersistedSlugLifecycleService
→ SlugLifecycleService
```

No additional assumed Domain Service layer exists in this path. `SlugEngine` passes the operation to `PersistedSlugLifecycleService`, which passes `assignExact` to `SlugLifecycleService`.

### 11.3 Integration Boundary and Concurrency

The path enters package-owned persistence through:

```text
Scope
Binding / Registry
History
Operation / Result Snapshot
Transaction coordination
```

These boundaries own the package repositories and mappers. The Host does not use their tables or reimplement their invariants. The unique Registry constraint is the final authority in an exact-claim race, and the result is mapped to the lifecycle contract without an automatic retry.

The transaction participation contract is:

```text
No outer transaction
→ package owns begin/commit/rollback

Outer transaction exists
→ package participates without committing/rolling back the caller transaction
→ savepoint is used when required and supported

Concurrent exact claim race
→ unique Registry constraint is authoritative
→ semantic duplicate handling follows the lifecycle contract
```

If the environment does not support the required participation capability, the path fails before mutation with `SlugTransactionParticipationException`. This does not change the Host's ownership of the outer transaction.

### 11.4 Observable Result

`assignExact` returns:

```text
SlugMutationResultDTO
```

Depending on the operation, the consumer receives:

```text
operationType
operationKey
replayed
before
after
affectedClaims
previousSlug
currentSlug
changeType
revision
historyEvents
```

[`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) explains this same path for consumers but does not create a competing technical contract; this Package Reference is the normative technical-contract source.

## 12. Exception Contract

The public markers are `SlugExceptionInterface` and `SlugDomainExceptionInterface`. The general families are:

```text
SlugValidationException
SlugBusinessRuleException
SlugConflictException
SlugNotFoundBaseException
SlugUnsupportedException
SlugSystemException
```

The locked concrete exceptions are:

```text
SlugInvalidArgumentException, SlugCannotBeGeneratedException,
SlugNotFoundException, SlugProfileConfigurationException,
SlugRuntimeCompatibilityException, SlugProfileAlreadyRegisteredException,
SlugProfileNotFoundException, SlugScopeProfileMismatchException,
SlugAssignmentNotPermittedException, SlugAliasOperationNotPermittedException,
SlugHistoricalRestoreNotPermittedException, SlugAlreadyClaimedException,
SlugReservedException, SlugRevisionConflictException,
SlugAllocationExhaustedException, SlugIdempotencyConflictException,
SlugTransferReplacementConflictException, SlugCurrentClaimReleaseException,
SlugPurgeNotPermittedException, SlugTransactionParticipationException,
SlugUnsupportedDriverException, SlugPersistenceInvariantException
```

The package uses the published hierarchy from `maatify/exceptions ^1.0`. It does not convert every SQLSTATE `23xxx` into a conflict; duplicate conversion is limited to MySQL `1062` with constraint context. Transaction catches rethrow the original throwable after rollback.

## 13. Evidence and Release State

Historical GitHub Actions run #9 on SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b` provides evidence for that SHA only, including:

- Composer validation, platform checks, and production autoload;
- PHPStan `level: max`, PHPUnit, and style or whitespace gates;
- Unit, Integration, System, Concurrency, and Transaction evidence with real MySQL; and
- two clean-state Consumer Verification Harness runs from an independent Composer root.

Historical `VG-001` verification passed on exact SHA `ff72d2e00a48eb5fbd55d81dea753a7f106187ef` through Actions run `35444700308`, followed by a passing Direct Lead Fresh Full Acceptance Review before integration. These historical results do not qualify any later HEAD.

Verification evidence is SHA-scoped: historical verification qualifies only the exact SHA stated and does not qualify later commits. Active integration and verification status is maintained in GitHub PR/CI history. Transient VG/PR state is not part of this public package contract, and this document makes no claim that the package is Stable, Published, or release-ready.

The current adopted Standards baseline is recorded by [`STANDARDS_MANIFEST.md`](docs/php-engineering-standards/STANDARDS_MANIFEST.md) at Adoption Commit `7dd9d1d02b53013da0906c729dab4f667afeefb4`. That manifest is the authoritative resolver record for the applicable Standard versions.

## 14. Supporting Documents

- [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) — consumer usage and integration.
- [`examples/`](examples/) — runnable consumer examples.
- [`CHANGELOG.md`](CHANGELOG.md) — documentation change history and publication state.
- [`SECURITY.md`](SECURITY.md) — support state and security reporting.
