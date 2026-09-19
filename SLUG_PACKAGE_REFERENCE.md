# Maatify Slug — Package Reference

> **Canonical root Package Reference** للحزمة `maatify/php-slug`.
>
> **الحالة الحالية:** تم الانتهاء من تنفيذ RC1. هذا الملف يسجل العقد المنفذ فعليًا وحدود الحزمة كما هي متوفرة حالياً في الـ Runtime (من الكود المصدري) و Tests و CI. الحزمة غير منشورة بعد على Packagist (لا توجد نسخة Stable أو SemVer RC منشورة للاستخدام العام).

## 1. هوية الحزمة وحالتها

| الحقل | القيمة الحالية |
|---|---|
| Composer package name | `maatify/php-slug` |
| Repository | `Maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| Artifact | standalone reusable PHP/Composer library |
| Host model | Host-agnostic |
| Current workflow state | Implemented (Unpublished) |
| Published Stable line | لا توجد |
| Published SemVer RC | لا توجد |

هذا المستودع يحتوي على التنفيذ الفعلي (الـ Runtime، Tests، Schema، CI)، ولكن لا يتوفر أمر تثبيت عبر `composer require` متاح للعموم قبل النشر الرسمي.

المراجع الدائمة المرتبطة بهذا الملف:

- [`docs/SLUG_LIBRARY_RC1_BLUEPRINT.md`](docs/SLUG_LIBRARY_RC1_BLUEPRINT.md): supporting architecture and public-contract contract.
- [`docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md`](docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md): execution source، dependency graph، evidence، وgates.
- [`docs/php-engineering-standards/STANDARDS_MANIFEST.md`](docs/php-engineering-standards/STANDARDS_MANIFEST.md): Local Resolver Record لمجموعة المعايير المنطبقة.

## 2. الغرض وحدود الملكية

RC1 هو **Authoritative Slug Lifecycle Engine** مستقل، وليس helper لتوليد النص فقط. تملك الحزمة generation وcanonicalization وscoped ownership وallocation وlifecycle وaliases وhistory وresolution وadoption وpackage-owned persistence، مع عقود concurrency وtransactions وresults وexceptions محددة.

تملك الحزمة:

- Slug generation وcanonicalization وlookup semantics.
- versioned Profiles وسياسة reserved-slug الممررة من Host.
- `SlugScope` و`EntityReference` كهوية domain مستقرة.
- current ownership وhistorical canonical ownership وactive/retired aliases.
- exact claiming وbounded generated allocation وavailability advisory.
- lifecycle transitions وrelease وpurge وscope transition وatomic transfer.
- immutable normal-lifecycle History وadoption وmanagement queries.
- Persistence المملوكة للحزمة عند استخدام المسار persisted.

يملك Host:

- الاتصال والإعداد وbootstrap وتهيئة `PDO` ووجود كيان Host وحياته.
- هوية `EntityReference` وقواعد التحقق من وجود الكيان.
- vocabulary وpatterns الخاصة بـreserved slugs.
- routing وfull path/URL construction وpercent encoding/decoding.
- HTTP controllers وmiddleware وstatus policy وredirects.
- SEO records وcanonical tags وhreflang وأي integration بين Slug وSEO.
- authentication وauthorization وAdmin UI وapplication bootstrap.

لا توجد Host foreign keys أو Host table joins أو Host repository dependencies، ولا تعتمد الحزمة على Framework أو ORM أو `maatify/php-seo`.

## 3. الحالة المدعومة والتثبيت

الحزمة متوفرة ككود مصدري (Runtime كامل، و`composer.json` موجود)، ولكنها **غير منشورة بعد**. الوصول المدعوم الآن هو استنساخ المستودع وقراءة هذا المرجع؛ لا تُستخدم `composer require maatify/php-slug` من مصادر عامة حتى النشر الرسمي.

الـ Runtime يوفر أمثلة فعلية منفذة. مسار الإنشاء العام المفصول عن Persistence المنفذ حاليًا هو:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$profiles->register($customProfile); // اختياري قبل إنشاء الخدمات
$text = SlugTextServiceFactory::create($profiles);
```

ومسار Persistence في العقد هو:

```php
$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
```

هذه أمثلة من الـ Runtime الحالي قابلة للتشغيل؛ حيث لا تنشئ أي Factory اتصالًا مخفيًا أو تقرأ `.env` أو تعمل Service Locator.

### 3.1 Source topology

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

Consumer وManagement داخل `Lifecycle/`، ولا تُعد Persistence Capability أو architecture root.

## 4. Runtime وPlatform contract لـRC1

تم استيفاء المتطلبات التالية وتتوفر بشكل فعلي في المستودع الحالي:

| المتطلب | القيمة |
|---|---|
| PHP | `^8.4`؛ المصفوفة الحالية المقصودة PHP 8.4 و8.5 دون PHP ceiling |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| ICU | major `74` وUnicode data `15.1` للـbuilt-in Profiles |
| Database adapter | PDO MySQL (يدعم MySQL-compatible semantics) |
| MySQL | Server `8.0.36` كـ CI reproducibility target |
| Runtime packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |
| Evidence tools | `phpstan/phpstan ^2.1`, `phpunit/phpunit ^11.5`, `friendsofphp/php-cs-fixer ^3.94` (متوفرة في `require-dev`) |

يجب أن يستخدم التنفيذ `ext-intl` في NFC وICU lowercase/transliteration، وأن يقتصر `ext-mbstring` على code-point length. اختلاف ICU أو Unicode tuple يفشل مغلقًا بـ`SlugRuntimeCompatibilityException` قبل أي mutation؛ لا يثبت PHP `^8.4` دعم أي ICU tuple آخر.

## 5. الهوية والنص

### 5.1 `Slug` و`Profile`

`Slug` هو decoded canonical URL path-segment token، وليس full path أو URL أو percent-encoded text. لا يملك public constructor؛ مسار الإنشاء الوحيد هو:

```php
Slug::fromProfile(SlugProfileInterface $profile, string $canonicalValue): Slug
```

ويستدعي `assertCanonicalSlug()` دون تحويل. لا توجد `fromRaw` أو `fromTrusted` أو hydration bypass.

المفاتيح المضمنة هي `unicode-v1` و`ascii-v1` فقط. Custom Profiles تستخدم key versioned يطابق:

```text
^(?=.{1,63}$)[a-z][a-z0-9-]*-v[1-9][0-9]*$
```

لا يجوز استبدال built-in key أو التسجيل المكرر؛ يُرفض بـ`SlugProfileAlreadyRegisteredException`.

### 5.2 العمليات الثلاث

| العملية | المعنى | القاعدة الأساسية |
|---|---|---|
| `generateFromSource` | source بشري | يسمح بالتحويلات lossy التي يعلنها Profile |
| `canonicalizeClaim` | exact candidate | NFC/lowercase والـequivalences المعلنة فقط؛ لا source cleanup |
| `canonicalizeLookup` | decoded URL segment | canonicalization الأضيق؛ لا generation-only transforms |

كل canonicalization idempotent على نطاقها. `hello!!!` في lookup يرفض ولا يتحول إلى `hello`.

### 5.3 Profiles والأمن والطول

`unicode-v1` يستخدم NFC وICU `Any-Lower` ويحافظ على Arabic، ويسمح canonical code points من `L/M/Nd` وASCII hyphen. `ascii-v1` يستخدم NFC ثم ICU `Any-Latin; Latin-ASCII` وASCII lowercase. مثالان مقفلان: `آيفون ١٧ برو` → `آيفون-١٧-برو` و`Über Café` → `uber-cafe`.

قبل أي lossy transform تُرفض invalid UTF-8 وNUL و`Cc` و`Cs` و`Cf` و`/` و`\\` والنتيجة الفارغة. mixed scripts مسموحة في `unicode-v1` ما دامت بقية القواعد مستوفاة.

الحد الأقصى هو 160 Unicode code points بعد canonicalization. exact claim/lookup الأطول يرفض؛ generated allocation تستخدم base ثم `-2` إلى `-1000` فقط، وتحسب طول اللاحقة قبل code-point truncation، ثم تزيل trailing hyphen وتعيد `SlugAllocationExhaustedException` عند الاستنفاد.

`namespace` يطابق `^[a-z][a-z0-9-]{0,62}$`. `localeKey` و`contextKey` و`entityType` و`entityKey` opaque، valid UTF-8، exact دون trim أو normalization أو case folding؛ `null` في locale/context يعني exact empty dimension فقط، وليس wildcard أو fallback. `profileKey` إعداد immutable للـScope وليس بُعد uniqueness.

## 6. Public API inventory

كل الأنواع العامة تحت `Maatify\\Slug\\`. كل interface تنتهي بـ`Interface`، وكل enum بـ`Enum`، وكل DTO وCommand وCriteria `final readonly`، وكل DTO يطبق `JsonSerializable`. لا تُكشف repositories أو SQL builders أو lock coordinators كـpublic API.

### 6.1 Interfaces والعمليات

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

`SlugProfileRegistryFactory::createBuiltIn()` و`SlugTextServiceFactory::create(SlugProfileRegistryInterface)` مساران stateless. `SlugEngineFactory::create(PDO, SlugProfileRegistryInterface, ReservedSlugPolicyInterface, ClockInterface)` يعيد `SlugEngine` النهائي للمسار persisted؛ لا يضيف `SlugEngine` contract أخرى.

### 6.2 Commands وCriteria

كل mutation method تستقبل Command واحدًا، ولا تستقبل raw parameter list. الفهرس الكامل للـCommands هو:

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

كلها تستخدم `BindingIdentityDTO` أو `ScopeProfileRequestDTO` والعقود التالية عند انطباقها: `AuditContextDTO`، `expectedRevision`، `ScopeTransitionClaimIntentDTO`، `TransferReplacementIntentDTO`، و`originalOccurredAt`. `expectedRevision = null` يثبت غياب Binding فقط؛ كل Binding موجود، بما فيه `RELEASED`، يتطلب revision الحالية.

والـconstructors العامة المقفلة هي:

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

كل Command `final readonly` ويتحقق من input contract فقط؛ لا ينفذ orchestration أو Persistence. `PurgeBindingCommand` وحده لا يحمل `AuditContextDTO` أو idempotency contract.

الفهرس الكامل للـCriteria هو:

```text
AvailabilityCriteria, ResolutionCriteria, BindingCriteria,
CurrentSlugCriteria, AliasCriteria, HistoryCriteria, RegistryCriteria,
ScopeCriteria, BindingSearchCriteria, RegistrySearchCriteria
```

تستخدم الـCriteria النوع المنشور `Maatify\\Persistence\\Pdo\\Pagination\\PageRequest` باعتباره المدخل الوحيد للـpagination وsort حيث يلزم. لا توجد `PageRequest` أو `PageResult` أو `SortDirectionEnum` محلية ولا `*PageDTO`.

constructors الـCriteria المقفلة هي:

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

قواعد الـCriteria هي query validation فقط؛ لا تعيد تعريف result DTO أو pagination mechanics.

### 6.3 DTOs وEnums

DTOs العامة هي:

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

Enums وقيمها المقفلة:

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

الـDTOs الناتجة تحمل قبل/بعد وrevision وclaims وHistory وفق العملية. `SlugResolutionDTO` يميز match kind عن binding status وinput canonicality. `SlugAvailabilityDTO` advisory دائمًا ولا يتحول إلى ضمان claim.

الحقول العامة المقفلة للـDTOs هي:

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

`ScopeProfileRequestDTO` يحمل `SlugScope $scope` و`SlugProfileKey $expectedProfileKey`، و`BindingIdentityDTO` يحمل `ScopeProfileRequestDTO $scopeProfile` و`EntityReference $entity`. تحمل intent DTOs `ClaimIntentModeEnum $mode` و`string $value`. يحمل `AuditContextDTO` الحقول الاختيارية `?string $actorKey`, `?string $reason`, `?string $correlationKey`, و`?string $idempotencyKey`. `HistoryEventDTO` وحقول role snapshots الخاصة به تتبع applicability المحددة في الـBlueprint §12.4 و§35.4.

## 7. Ownership وLifecycle

الهوية الحية للـclaim هي `(Scope, canonical Slug)`. `EntityReference` هو `(entityType, entityKey)` كما يقدمه Host؛ الحزمة لا تتحقق من وجود الكيان ولا تعيد تفسير هويته.

### 7.1 Registry وBinding

| المفهوم | العقد |
|---|---|
| Scope identity | `namespace + localeKey + contextKey`; `profileKey` ليس جزءًا من uniqueness |
| Binding states | `ACTIVE`, `INACTIVE`, `RELEASED` |
| Live roles | `CURRENT_CANONICAL`, `HISTORICAL_CANONICAL`, `ACTIVE_ALIAS`, `RETIRED_ALIAS` |
| ACTIVE/INACTIVE | يجب أن يملكا current pointer وRegistry claim صالحًا |
| RELEASED | بلا Registry claims وبلا current pointer |
| History | immutable normal-lifecycle snapshots؛ لا يعتمد على بقاء Registry |

التغيير العادي يحفظ الـprevious canonical كـhistorical ولا يحرر ownership. retired aliases تبقى محجوزة. `releaseClaim` يحرر non-current claim فقط، و`releaseAllOwnership` يمسح كل claims ويضع Binding في `RELEASED`. `purgeBinding` destructive ومسموح لـ`RELEASED` بلا claims فقط؛ بعد نجاحه ينتهي ضمان replay الخاص بذلك Binding.

### 7.2 العمليات المركبة

- `transitionScope` ينشئ target Binding جديدًا بعد target-profile canonicalization. `MOVE` يجعل source `INACTIVE`، و`PARALLEL` يبقي source دون mutation؛ لا يعيد كتابة `scope_id`.
- `atomicTransfer` same Scope فقط، إلى target Binding موجودة قبل العملية. ينقل claim في transaction واحدة؛ current يتطلب replacement مختلفًا، وتحفظ role الأصلية حرفيًا في الهدف. لا يدعم RC1 cross-scope transfer.
- `adoptCurrent` يسمح بـabsent أو `RELEASED` وفق `expectedRevision`، بينما `adoptHistorical` و`adoptAlias` يتطلبان Binding `ACTIVE/INACTIVE` مع current قائم.
- `originalOccurredAt` في adoption هو `DateTimeImmutable` بأي timezone، يحول إلى UTC ويحفظ microseconds الست، ويرفض فقط خارج مدى MySQL `DATETIME(6)`؛ لا توجد مقارنة future/past مع Clock.

## 8. Replay وResult Snapshot

idempotency اختياري. عند غياب key تكون mutation طبيعية بلا operation أو participant evidence، و`operationKey = null` و`replayed = false`. عند وجود key تُحفظ operation evidence وrequest fingerprint SHA-256 وResult Snapshot immutable.

Result Snapshot JSON v1 له أربعة discriminators فقط:

| `result_type` | aggregate |
|---|---|
| `mutation` | `SlugMutationResultDTO` للعمليات الأحادية فقط |
| `transition` | `ScopeTransitionResultDTO` |
| `transfer` | `AtomicTransferResultDTO` |
| `adoption` | `AdoptionResultDTO` |

`result_schema_version` هو JSON integer `1`، والشكل العلوي وترتيب مفاتيحه `result_type`, ثم `result_schema_version`, ثم `result`. الـsnapshot compact UTF-8، immutable، وليس dump للحالة الحية. replay يقرأ snapshot الملتزم ويعيد DTO نفسه مع `replayed = true` في الذاكرة فقط؛ لا يعيد بناء النتيجة من Registry الحالية ولا ينشئ History أو revision جديدة. أي mismatch في metadata أو schema أو nested shape يرمى `SlugPersistenceInvariantException`.

في current `atomicTransfer` فقط يجوز أن تحتوي النتيجة nested `sourceReplacementResult`؛ تكون `operationKey` و`replayed` مساويتين للـouter result. لا يسمح top-level `mutation` بـ`ATOMIC_TRANSFER`.

## 9. Persistence وTransactions وConcurrency

المسار persisted يستخدم direct PDO داخل package infrastructure فقط:

- `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION` و`PDO::ATTR_EMULATE_PREPARES = false`.
- connection charset `utf8mb4`، وtimestamps من `ClockInterface` إلى UTC `DATETIME(6)`؛ لا تستخدم DB defaults كمصدر سلوكي.
- لا ORM ولا external query builder، ولا SQLite/MariaDB/PostgreSQL fallback.
- package-owned transaction عند غياب outer transaction؛ caller-owned transaction لا تعمل الحزمة لها commit أو rollback.
- nested participation تستخدم savepoint إذا كانت capability مدعومة، وإلا يفشل التنفيذ قبل mutation بـ`SlugTransactionParticipationException`.
- unique database constraints هي claim authority النهائية. duplicate يتحول semantic فقط عند MySQL `errorInfo[1] === 1062` مع constraint context معروف؛ غير ذلك يعاد كـThrowable infrastructure مع الحفاظ على `previous` عند wrapping.
- first-create races على Binding وScope تعاد قراءتها تحت lock في المسارات المحددة؛ لا يوجد savepoint خاص بمحاولة `INSERT` ولا automatic deadlock retry.
- `revision` تحمي same-binding CAS، وmulti-row lifecycle/history mutations atomic. ترتيب locks في transfer/transition جزء من العقد التنفيذي.

عند failure أو invariant violation يجب أن تزول mutation الجزئية والـHistory والـoperations غير الملتزمة بالـrollback العام للعملية، ويعاد الـThrowable الأصلي ما لم يوجد semantic conversion موثق.

## 10. Management وResolution وPagination

`resolve` يقرأ decoded segment ويعيد `SlugResolutionDTO` حتى في `NONE` و`INVALID`. القيم `MatchKindEnum` تميز `CURRENT` و`ALIAS` و`HISTORICAL` و`RETIRED_ALIAS` و`NONE`، ولا تعيد resolution live لـ`RELEASED`. `INACTIVE` يبقى قابلًا للمطابقة؛ قرار 404/410/redirect يملكه Host.

Management queries تفصل live Registry عن History:

```text
listAliases, getHistory, inspectRegistry, searchBindings, searchRegistry
```

كل list/search غير محدود يستخدم `PageResult` من `maatify/persistence ^1.1`. Slug يملك filters وselected columns وcount/data predicate alignment وrow mapping، و`PdoPaginator` يملك mechanics المشتركة. القيم package-specific الحالية:

```text
defaultPerPage = 25
minPerPage = 1
maxPerPage = 100
tie-breaker = id ASC
```

لا توجد local pagination DTOs أو enums أو paginator، ولا يفسر Host هذه العقود عبر generic filters أو Admin UI.

### 10.1 Operational Read / Reporting

```text
Classification: IN SCOPE
```

الحزمة تملك حالة Slug persisted ذات معنى تشغيلي، وتشمل:

- `Scopes`.
- `Bindings`.
- `Registry claims`.
- `History`.
- حالات دورة الحياة و`revisions`.

لذلك لا تعيد الحزمة تصنيف Operational Read / Reporting على أنها خارج النطاق، ولا يضطر Host إلى قراءة جداول الحزمة مباشرة لإعادة بناء معاني هذه الحالة. العقد العام المستقر للقراءة التشغيلية هو `SlugManagementQueryInterface`، وهو read-only، وتفصل الحزمة بين `Management API` و`Consumer API` بعقود مستقلة؛ لا يمثل جمعهما في واجهة `SlugEngine` تغييرًا لهذا الفصل.

العقد وsemantics الحالية هي:

```text
getBinding(BindingCriteria)
→ exact Binding identity، ويرجع BindingDTO أو null

getCurrent(CurrentSlugCriteria)
→ current canonical slug read، ويرجع CurrentSlugDTO أو null

listAliases(AliasCriteria)
→ aliases الخاصة بـBinding واحد مع pagination

getHistory(HistoryCriteria)
→ History الخاصة بـBinding واحد مع optional HistoryEventTypeEnum وpagination

inspectRegistry(RegistryCriteria)
→ Registry الخاصة بـScope واحدة مع optional Binding وoptional RegistryRoleEnum وpagination

inspectScope(ScopeCriteria)
→ قراءة Scope المطابقة لـScopeProfileRequestDTO، وترجع ScopeDTO أو null

searchBindings(BindingSearchCriteria)
→ Scope مع optional entityType وentityKeyPrefix وBindingStatusEnum وpagination

searchRegistry(RegistrySearchCriteria)
→ Scope مع optional slugPrefix وRegistryRoleEnum وBindingStatusEnum وpagination
```

هذه Management API لا تعدل الحالة ولا تمثل مسار mutation بديلًا. تملك الحزمة معاني `Slug` scopes وbindings وclaims وhistory وstatus وrevision، بينما يملك Host العرض والصلاحيات وHTTP وexports ومعنى الكيانات وأسماءها وتجميع البيانات بين الحزم.

لا تملك RC1 حاليًا public reporting contracts لـ:

```text
generic dashboard
aggregate/count/grouped metrics
time-window reporting filters
CSV/PDF/Excel exports
Host actor/name resolution
cross-package reporting
```

ولا تضيف الحزمة API لهذه الأبعاد لمجرد استيفاء معيار Reporting. كما أن internal operation أو idempotency rows، رغم كونها persisted، ليست public reporting contract.

## 11. Technical Consumer Workflow

المسار التقني المعياري للمستهلك في RC1 هو مسار واحد مترابط:

```text
Host Input
→ Public API
→ Domain Service
→ Integration Boundary
→ Observable Result
```

### 11.1 Host Input and Construction

يوفر Host المدخلات والعقود التالية:

```text
PDO مطابق للـruntime contract
SlugProfileRegistryInterface
ReservedSlugPolicyInterface
ClockInterface

BindingIdentityDTO
slug candidate
expectedRevision
AuditContextDTO
```

يمكن تكوين الـbuilt-in Profiles عبر المسار العام المدعوم، ثم إنشاء الـstateful engine عبر Factory:

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

لا تنشئ الـFactory اتصالًا مخفيًا ولا تقرأ إعدادات Host أو `.env` من تلقاء نفسها.

### 11.2 Public API and Domain Path

يستخدم المستهلك `assignExact` عبر Command واحد:

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

والـdomain path الفعلي هو:

```text
SlugEngine
→ PersistedSlugLifecycleService
→ SlugLifecycleService
```

لا توجد طبقة Domain Service إضافية مفترضة في هذا المسار. `SlugEngine` يمرر العملية إلى `PersistedSlugLifecycleService`، الذي يمرر `assignExact` إلى `SlugLifecycleService`.

### 11.3 Integration Boundary and Concurrency

يدخل المسار حدود Persistence المملوكة للحزمة عبر:

```text
Scope
Binding / Registry
History
Operation / Result Snapshot
Transaction coordination
```

تتولى هذه الحدود repositories وmappers الخاصة بالحزمة، ولا يتعامل Host مع جداولها أو يعيد تنفيذ invariants الخاصة بها. الـunique Registry constraint هي السلطة النهائية في race الخاصة بـexact claim؛ وتتحول النتيجة إلى semantic duplicate وفق عقد lifecycle، دون automatic retry.

عقد transaction والمشاركة هو:

```text
No outer transaction
→ package owns begin/commit/rollback

Outer transaction exists
→ package participates without committing/rolling back caller transaction
→ savepoint is used when required/supported

Concurrent exact claim race
→ unique Registry constraint is authoritative
→ semantic duplicate handling follows lifecycle contract
```

إذا لم تدعم البيئة capability المطلوبة للمشاركة، يفشل المسار قبل mutation بـ`SlugTransactionParticipationException`. لا يغير هذا العقد ownership الخاص بـHost للـouter transaction.

### 11.4 Observable Result

يعيد `assignExact` الناتج العام:

```text
SlugMutationResultDTO
```

ويعرض للمستهلك، حسب العملية، الحقول التالية:

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

يمكن لـUsage Guide المستقبلية ضمن AF-012 أن تعرض هذا المسار نفسه للمستهلك، لكنها لا تنشئ technical contract منافسة؛ Package Reference هو مصدر العقد التقني المعياري.

## 12. Exception contract

الـmarker العام هو `SlugExceptionInterface` و`SlugDomainExceptionInterface`. الـfamilies العامة هي:

```text
SlugValidationException
SlugBusinessRuleException
SlugConflictException
SlugNotFoundBaseException
SlugUnsupportedException
SlugSystemException
```

والـconcrete exceptions المقفلة هي:

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

تستخدم الحزمة hierarchy المنشورة من `maatify/exceptions ^1.0`. لا تُحوّل كل SQLSTATE `23xxx` إلى conflict؛ التحويل الخاص بالـduplicate محصور في MySQL `1062` مع constraint context. transaction catch يعيد الـThrowable الأصلي بعد rollback.

## 13. Evidence وRelease state

يحدد الـPlan evidence الذي تم تحقيقه بنجاح عبر CI، بما فيه:

- Composer validation وplatform checks وproduction autoload.
- PHPStan `level: max` وPHPUnit والـstyle/whitespace gates.
- Unit وIntegration وSystem/Concurrency/Transaction evidence مع بيئة MySQL الحقيقية.
- Consumer Verification Harness من Composer root مستقل مرتين من clean states.

**ملاحظة:** المراجعة النهائية (Fresh Full Acceptance Review, مراجعة accumulated diff, و Full Applicable Integration Gate) تبقى معلقة ولن تكتمل حتى يتم قبول هذا الفرع تنفيذيًا. نجاح أدلة الـ CI المذكورة أعلاه يثبت اجتياز بوابات الجودة الحالية، ولا يعتبر وعدًا بالدعم (public support promise) أو إثباتاً لـ production deployment قبل النشر النهائي (Release أو Packagist publication).

## 14. Supporting documents

- [`docs/SLUG_LIBRARY_RC1_BLUEPRINT.md`](docs/SLUG_LIBRARY_RC1_BLUEPRINT.md) — التفاصيل المعمارية والعقود التنفيذية المقفلة.
- [`docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md`](docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md) — Work Units وdependency graph وverification gates.
- [`CHANGELOG.md`](CHANGELOG.md) — تاريخ التغييرات التوثيقية وحالة النشر.
- [`SECURITY.md`](SECURITY.md) — حالة الدعم ومسار البلاغات الأمنية.
