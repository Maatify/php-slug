# Maatify Slug — RC1 Blueprint

> **الحالة:** عقد تصميم دائم قابل للتنفيذ لـRC1، وليس سجلًا بأن التنفيذ موجود.
>
> **المستودع:** `Maatify/php-slug`
> **Composer package:** `maatify/php-slug`
> **Namespace:** `Maatify\\Slug\\`
> **Baseline التصميم للتأليف:** `006ca7c62b4defc62c8ef2b16374b6f60d48a8dc` على `work/rc-1-preparation`
> **مصدر التنفيذ اللاحق:** HEAD المحدث لـ`phase-draft/rc-1` بعد إغلاق Preparation ودمج PR #2

هذا المستند ينقل القرارات المقبولة من `docs/SLUG_LIBRARY_RC_CONCEPT_DISCUSSION.md` إلى عقد تنفيذ محدد. تظل مسودة النقاش مدخلًا معماريًا مقبولًا، لكنها ليست مصدرًا بديلًا عن هذا الـBlueprint عند التنفيذ. لا يثبت هذا المستند وجود Runtime أو Schema أو Tests؛ تلك نتائج يجب إنتاجها والتحقق منها وفق خطة التنفيذ.

## 1. الغرض والنطاق

يعرّف RC1 محرك دورة حياة Slug مستقلًا يملك generation وcanonicalization وscoped ownership وallocation وlifecycle وaliases وhistory وresolution وadoption وpersistence المتزامنة. يستطيع المستهلك استخدام generation/canonicalization دون Persistence، أو يحقن اتصال PDO ويستخدم دورة الحياة المملوكة للحزمة.

الهدف هو إغلاق النموذج الأساسي قبل كتابة Runtime، مع إبقاء الإضافات المستقبلية في profile أو policy أو adapter دون إعادة تعريف هوية Slug أو Scope أو Binding أو Registry.

## 2. الحالة الحالية وحدود هذا المستند

عند baseline المذكورة أعلاه لا توجد `composer.json` أو `src/` أو `tests/` أو `schema/` أو CI implementation في هذا المستودع؛ الموجود هو مسودة النقاش ونسخة المعايير المثبتة. لذلك تستخدم الصياغة الآتية `يجب أن ينفذ RC1` و`دليل القبول المطلوب`، ولا تستخدمها كإثبات لقدرة منفذة.

لا ينشئ هذا المستند ملفات PHP أو SQL أو migrations أو tests أو workflows أو Composer metadata. الـpseudo-DDL أدناه عقد تصميم فقط.

## 3. المصدر المعياري وحدود الملكية

تطبق على التنفيذ Snapshot المعايير المسجل في `docs/php-engineering-standards/STANDARDS_MANIFEST.md` عند adoption commit `2fc57f9320f8a7f7147fb20abbcfa311fdf40c28`:

- `std-package-building` `1.3.0`؛
- `std-composer-package` `1.2.0`؛
- `std-ci-workflow` `1.1.0`؛
- `std-library-presentation` `1.0.1`؛
- `std-testing` `1.1.0`؛
- `std-ai-collaboration-workflow` `6.0.0`؛
- `std-github-phase-stack-workflow` `2.2.0`.

تملك الحزمة Slug domain وPersistence الخاصة بها. يملك Host الاتصال والإعداد والـbootstrap ووجود كيان Host وأي سياسة HTTP أو SEO. لا توجد Host FKs أو Host JOINs، ولا تعتمد الحزمة على Framework أو ORM أو `maatify/php-seo`.

## 4. المصطلحات والعقود العامة

### 4.1 Slug

`Slug` هو token دلالي يمثل canonical decoded URL path-segment. لا يكون full path أو URL أو percent-encoded text. قيمة الـSlug وحدها لا تكفي لتمييز ownership؛ هوية claim الحية هي `(scope, canonical slug)`.

### 4.2 Profile

`SlugProfile` هو عقد versioned يعرّف generation وexact-claim canonicalization وlookup canonicalization وvalidation وlength وUnicode/security. هوية Profile هي `profileKey` النصي الإصدارّي، وليست صف Scope إضافيًا ولا جزءًا من uniqueness.

### 4.3 Scope

هوية `SlugScope` هي بالضبط:

```text
namespace + localeKey + contextKey
```

`profileKey` إعداد immutable ملحق بهذه الهوية وليس بُعد uniqueness. لا يجوز أن توجد ownership universes متوازية لنفس scope بسبب اختلاف profile.

### 4.4 EntityReference

هوية `EntityReference` هي `(entityType, entityKey)` كما يقدمهما Host. القيم opaque ومستقرة؛ لا تتحقق الحزمة من وجود الكيان ولا تعيد تفسير نوع تخزينه ولا تطبق case folding أو Unicode normalization عليهما.

### 4.5 Binding

هوية Binding هي `(SlugScope, EntityReference)`. `scope_id` للـBinding ثابت؛ الانتقال إلى Scope أخرى ينشئ Binding الهدف ولا يغير صف Binding المصدر.

### 4.6 Registry Claim

كل canonical أو history أو alias الحي هو صف واحد في Registry داخل Scope واحدة. Registry هي مصدر ownership الحي. غياب الصف بعد explicit release يعني أن القيمة قابلة لإعادة claim، حتى لو بقيت snapshot في History.

### 4.7 History

History immutable retained normal-lifecycle timeline. يحفظ snapshots مفهومة بذاتها ولا يعتمد على بقاء Registry row. Purge الصريح هو الاستثناء الوحيد الذي يمحو تاريخ الحزمة وفق عقده.

## 5. الحدود العامة والداخلية

### 5.1 Public stable boundaries

تكون الأنواع العامة تحت `Maatify\\Slug\\`، وتكون كل interface منتهية بـ`Interface`، وكل enum منتهية بـ`Enum`، وكل DTO منتهية بـ`DTO`، وكل exception منتهية بـ`Exception`. كل Command هو `final readonly`، وكل DTO هو `final readonly` ويطبق `JsonSerializable`، وCollections تطبق `IteratorAggregate` و`JsonSerializable`.

العقود العامة المحددة لـRC1 هي:

| Boundary | العقد |
|---|---|
| Stateless text | `SlugTextServiceInterface` |
| Profiles | `SlugProfileInterface`, `SlugProfileRegistryInterface` |
| Reserved policy | `ReservedSlugPolicyInterface` |
| Scope establishment | `SlugScopeRegistryInterface` |
| Lifecycle mutation | `SlugLifecycleServiceInterface` |
| Resolution/availability | `SlugQueryServiceInterface` |
| Management | `SlugManagementQueryInterface` |
| Construction | `SlugEngineFactory` و`SlugEngine`؛ factory final framework-neutral وليست Service Locator ولا contract لاستبدال PDO |

لا تُكشف repositories الداخلية أو SQL builders أو lock coordinators كـstable public API. إذا أريد استبدال infrastructure في Runtime، يتم ذلك خلف interface عامة محددة أعلاه أو contract أصغر خاص بحد الاستبدال الفعلي؛ لا يضطر Host إلى معرفة جداول الحزمة.

### 5.1.1 عمليات العقود العامة

هذه هي signatures العامة الملزمة (بصياغة PHP 8.4)؛ الحقول وقواعد التحقق موثقة بصورة كاملة في §35. لا يجوز للتنفيذ استبدال result aggregate أو إضافة قرار type أثناء التنفيذ:

```php
interface SlugTextServiceInterface
{
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO;
    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO;
    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO;
}

interface SlugProfileInterface
{
    public function key(): SlugProfileKey;
    public function generateFromSource(string $source): GeneratedSlugDTO;
    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO;
    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO;
    public function validateCanonicalSlug(string $candidate): Slug;
}

interface SlugProfileRegistryInterface
{
    public function register(SlugProfileInterface $profile): void;
    public function get(SlugProfileKey $key): SlugProfileInterface;
    public function has(SlugProfileKey $key): bool;
}

interface ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, Slug $slug): bool;
}

interface SlugScopeRegistryInterface
{
    public function ensureScope(
        ScopeProfileRequestDTO $request,
        ?AuditContextDTO $audit = null,
    ): ScopeDTO;
}

interface SlugLifecycleServiceInterface
{
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO;
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO;
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO;
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO;
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO;
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO;
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO;
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO;
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO;
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO;
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO;
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO;
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO;
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO;
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO;
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO;
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO;
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO;
    public function purgeBinding(PurgeBindingCommand $command): void;
}

interface SlugQueryServiceInterface
{
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO;
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO;
}

interface SlugManagementQueryInterface
{
    public function getBinding(BindingCriteria $criteria): ?BindingDTO;
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;
    public function listAliases(AliasCriteria $criteria): AliasCollectionDTO;
    public function getHistory(HistoryCriteria $criteria): HistoryPageDTO;
    public function inspectRegistry(RegistryCriteria $criteria): RegistryPageDTO;
    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO;
    public function searchBindings(BindingSearchCriteria $criteria): BindingPageDTO;
    public function searchRegistry(RegistrySearchCriteria $criteria): RegistryPageDTO;
}
```

`getCurrent` يستخدم `null` فقط عندما لا يوجد current حي؛ أما `resolve` فيعيد `SlugResolutionDTO` دائمًا ليحفظ حالات `NONE` و`INVALID` وcanonicality دون فقدان الدليل. invalid input أو profile mismatch أو persistence failure خارج نتيجة resolution هي exceptions محددة. `ScopeProfileRequestDTO` هو contract حقيقي مشترك، وليس placeholder يمكن للتنفيذ استبداله.

لا يقبل `SlugTextServiceInterface` PDO ولا يكتب Persistence. كل lifecycle method يقبل Command واحدًا لا raw parameter list غير موثق، وكل query filter يقبل Criteria أو query contract صريح.

### 5.2 Internal boundaries

تقسم Runtime، عند وجود مسؤولية حقيقية، إلى `Profile`, `Generation`, `Canonicalization`, `Identity`, `Scope`, `Lifecycle`, `Allocation`, `Resolution`, `History`, `Adoption`, `Management`, `Persistence`, و`Exception`. يمكن أن تبقى المكونات الصغيرة في مجلد capability واحد؛ لا تنشأ مجلدات فارغة لمجرد مطابقة الرسم.

الطبقات الداخلية هي:

```text
Public Command/Query → Domain Service → Internal policy/transaction coordinator → PDO Repository
```

SQL لا يوجد في Services أو Commands. Commands تتحقق من مدخلاتها فقط، وCriteria تتحقق من query filters، وServices تنسق business rules، وPDO repositories تنفذ SQL وتعيد DTOs أو نتائج Repository المحددة.

### 5.3 ما لا تملكه الحزمة

لا تشمل RC1 routes أو full path/URL builders أو URI percent encode/decode أو HTTP controllers/middleware/statuses أو redirect/SEO records/canonical tags/hreflang أو Framework model hooks/listeners/traits أو automatic title monitoring أو Host entity persistence/existence checks أو Host authentication/authorization/Admin UI أو application bootstrap. لا توجد dependency مباشرة بين Slug وSEO في أي اتجاه.

## 6. هوية الأنواع والقيم

### 6.1 `Slug`

`Slug` value object يحمل string canonical فقط. لا يسمح منشئه العام بقيمة غير validated؛ profile هو الذي ينشئه بعد canonicalization. لا تدخل profile أو scope في string نفسها. يسجل DTO السياق عند الحاجة.

### 6.2 `SlugProfileKey`

لأن custom profiles مطلوبة، يكون المفتاح value object لا enum. الشكل المسموح:

```text
^(?=.{1,63}$)[a-z][a-z0-9-]*-v[1-9][0-9]*$
```

المفاتيح المضمنة الوحيدة في RC1 هي `unicode-v1` و`ascii-v1`. لا يستطيع consumer استبدال سلوك مفتاح مضمن أو تسجيل مفتاح مضمن مرة ثانية. لا يتجاوز طول أي `SlugProfileKey` كامل 63 محرف ASCII.

### 6.3 `SlugScope`

يحمل `namespace: string` و`localeKey: ?string` و`contextKey: ?string`. لا يحمل profile؛ profile يأتي في `ScopeProfileRequestDTO` أو في Command كـexpected configuration. تمثيل `null` هو exact empty dimension وليس wildcard أو fallback. `ScopeProfileRequestDTO` يثبت pairing المطلوب ولا يسمح للـScope أن تتبدل Profile ضمن هويتها:

```php
final readonly class ScopeProfileRequestDTO implements \JsonSerializable
{
    public function __construct(
        public SlugScope $scope,
        public SlugProfileKey $expectedProfileKey,
    ) {}
}
```

يجب أن تكون `expectedProfileKey` موجودة في `SlugProfileRegistryInterface` قبل الاستدعاء؛ اختلافها عن Profile المحفوظة يرمى `SlugScopeProfileMismatchException` قبل أي Binding أو Registry أو History write.

### 6.4 `EntityReference`

يحمل `entityType: string` و`entityKey: string`. يحافظ على النص كما استلمه Host ولا ينشئ slugًا منه ولا يطبع قيمته في URL.

## 7. Profile contracts

كل Profile يجب أن ينفذ العمليات المنفصلة الآتية، ولو شارك primitives داخلية:

| العملية | مدخلها | السماح |
|---|---|---|
| `generateFromSource` | human/source text | transforms lossy المعلنة في Profile |
| `canonicalizeClaim` | exact candidate | equivalences المعلنة فقط؛ لا تحويل source عام |
| `canonicalizeLookup` | decoded URL segment | equivalences الأضيق المعلنة؛ لا generation-only loss |
| `validateCanonicalSlug` | canonical candidate | قبول/reject دون اختراع قيمة |

كل عملية canonicalization idempotent على نطاقها. `canonicalizeClaim(canonical)` و`canonicalizeLookup(canonical)` يعيدان القيمة ذاتها، وlookup لا يحول `hello!!!` إلى `hello` إلا إذا أضاف Profile هذا equivalence صراحة؛ RC1 لا يضيفه.

### 7.1 `unicode-v1`

- Unicode normalization: NFC عبر `ext-intl`؛ فشل Normalizer يرفض المدخل.
- Case: `mb_strtolower(..., 'UTF-8')` عبر `ext-mbstring`.
- Allowed canonical code points: Unicode letters `L`، marks `M`، decimal digits `Nd`، وASCII hyphen `U+002D` فقط.
- Separator: hyphen ASCII واحد؛ source generation تستبدل كل run من غير المسموح به، عدا security-invalid input، بـhyphen ثم collapse وtrim.
- Exact claim: يطبق NFC وlowercase فقط، ثم يرفض space أو punctuation أو duplicate/leading/trailing hyphen؛ لا يحولها إلى hyphen.
- Lookup: يطبق NFC وlowercase فقط ثم نفس validation؛ لا trim ولا punctuation removal ولا whitespace-to-hyphen.
- Arabic يبقى Unicode؛ مثال canonical vector: `آيفون ١٧ برو` → `آيفون-١٧-برو`.

### 7.2 `ascii-v1`

- Unicode normalization الأولي: NFC.
- Transliteration engine: ICU `Transliterator` عبر `ext-intl`، بالمعرف الثابت `Any-Latin; Latin-ASCII`.
- Compatibility contract: RC1 يدعم ICU major `74` فقط. تشغيل Profile على ICU major مختلف يفشل بـ`SlugProfileConfigurationException` قبل أي mutation؛ هذا قيد determinism وليس ادعاء portability.
- Case: ASCII lowercase بعد transliteration.
- Allowed canonical code points: `a-z`, `0-9`, وASCII hyphen فقط.
- Source generation: security validation ثم transliteration ثم تحويل runs غير المسموح بها إلى hyphen، collapse وtrim.
- Exact claim: يقبل ASCII candidate بعد lowercase equivalence فقط؛ لا يشغل transliteration ولا يحول spaces/punctuation.
- Lookup: يقبل ASCII candidate بعد lowercase equivalence فقط؛ لا يشغل transliteration أو lossy separator transforms.
- canonical vector: `Über Café` → `uber-cafe`.

### 7.3 Custom profiles

`SlugProfileRegistryInterface` يسجل Profile object قبل استعمال Scope له. custom key versioned ومطابق لشكل `SlugProfileKey`، ولا يجوز أن يحل محل built-in key. consumer مسؤول عن ثبات behavior وdata mappings طالما Scope persisted تشير إلى المفتاح. عدم توفر Profile المشار إليه في Scope يفشل مغلقًا بـ`SlugProfileNotFoundException`.

## 8. Unicode والأمن والطول

قبل أي lossy generation أو claim أو lookup، ترفض Profile:

- invalid UTF-8؛
- NUL؛
- كل C0/C1 controls `Cc`، بما فيها `DEL`؛
- surrogate code points `Cs`؛
- كل Unicode format/bidi code points `Cf`، بما فيها ZWJ وbidi overrides في RC1؛
- `/` و`\\` في slug input؛
- canonical empty result.

لا تفرض RC1 mixed-script ban. `iPhone-١٧` صالح في `unicode-v1` إذا استوفى بقية العقد. policy boundary هي Profile SPI، ويمكن لـcustom Profile اختيار سياسة مختلفة بعقد versioned مستقل.

الحد الأقصى للـSlug هو **160 Unicode code points** بعد canonicalization. `mb_strlen`/`mb_substr` أو equivalent code-point operations مطلوبة؛ byte truncation ممنوعة. لا يعد RC1 بسلامة grapheme clusters، لكنه لا يقطع UTF-8 byte sequence. Source generation تقصر على 160 code points، تزيل trailing hyphen، وexact claim/lookup يرفضان القيمة الأطول بدل قص exact identity.

لـautomatic allocation، يحجز algorithm طول `-N` قبل القص: `baseLimit = 160 - (1 + digits(N))`، يقص base على code-point boundary ثم يزيل trailing hyphen، ويرفض إذا لم يبق token صالح. يبدأ التسلسل بالـbase ثم `-2` إلى `-1000` فقط؛ تجاوز 1000 محاولة يعطي `SlugAllocationExhaustedException`.

## 9. Validation لقيم Scope وEntityReference

### 9.1 `namespace`

ASCII lowercase فقط، pattern:

```text
^[a-z][a-z0-9-]{0,62}$
```

لا trim ولا case conversion في value object؛ المدخل المخالف يرفض.

### 9.2 القيم opaque

`localeKey` و`contextKey` و`entityType` و`entityKey` يجب أن تكون valid UTF-8، غير فارغة عند تمريرها، ضمن 191 Unicode code points، وألا تحتوي NUL أو C0/C1 أو `Cs` أو `Cf` أو `/` أو `\\` أو leading/trailing ASCII/U+00A0 whitespace. punctuation وinternal whitespace مسموحان. لا ينفذ package normalization أو trim أو case folding؛ القيمة المخزنة هي exact Host representation.

`entityType` يطبق حدًا أقصى 63 code points، و`entityKey` 191. `localeKey` و`contextKey` nullable فقط؛ تمرير string فارغ صراحة يرفض.

### 9.3 canonical empty scope dimensions

في API، `null` فقط يعني غياب locale/context. في MySQL، كلا العمودين `NOT NULL VARCHAR(191)` ويخزن `null` كـempty string `''`. لذلك لا تدخل `profileKey` في unique scope key، ولا تعتمد uniqueness على SQL NULL. Scope key canonical هو `(namespace, '', '')` عند غياب البعدين.

## 10. Reserved policy وgeneration failure

`ReservedSlugPolicyInterface` عقد pure decision:

```text
isReserved(SlugScope $scope, Slug $slug): bool
```

Host يملك vocabulary/patterns، والحزمة لا تستعلم Host tables. يفحص claim/allocation/adoption policy قبل إنشاء ownership حية. ترتيب availability هو: `INVALID` ثم same-binding ownership ثم `OWNED_BY_OTHER_BINDING` ثم `RESERVED` ثم `AVAILABLE`. same-binding restore/promotion لا تعاقبه reservation لاحقة؛ atomic transfer إلى binding أخرى يفحص reservation على الهدف.

إذا أعطت source generation canonical empty result أو فشلت سياسة Profile، يرمى `SlugCannotBeGeneratedException`. لا توجد fallback قيم مخترعة مثل `item-123`.

## 11. Driver contract لـRC1

RC1 يدعم **PDO MySQL فقط** مع **MySQL Server 8.0.36**. `pdo_mysql` و`ext-pdo` مطلوبان. لا يدعي RC1 دعم MariaDB أو PostgreSQL أو SQLite أو أي PDO driver آخر؛ adapters إضافية خارج هذه المرحلة.

إعداد الاتصال الذي تملكه طبقة Host ويحقنه Host:

- `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION`؛
- `PDO::ATTR_EMULATE_PREPARES = false`؛
- connection charset `utf8mb4`؛
- session timezone لا يغير timezone العالمي لـPHP؛ timestamps تكتب UTC من Clock.

كل SQL يستخدم direct PDO داخل `Infrastructure/Persistence/PDO`، unique placeholder لكل ظهور في statement، `PDO::PARAM_INT` لـLIMIT/OFFSET، وhydration annotations لـ`mixed` rows. لا ORM ولا external query builder.

### 11.1 خطأ duplicate

لا تكفي SQLSTATE class `23`. في adapter MySQL لا يحول Duplicate إلا إذا كان `PDOException::$errorInfo[1] === 1062` مع معرفة العملية واسم constraint المتوقع. غير ذلك يعاد كما هو، مع الحفاظ على `previous` عند semantic wrapping. duplicate في `uk_registry_scope_slug` هو race/claim conflict؛ duplicate في Scope identity يعالج فقط في bootstrap path؛ أي duplicate آخر infrastructure failure ما لم يثبت العكس.

## 12. Persistence schema العقدي

ما يلي pseudo-DDL وصفي لا يُحفظ كملف SQL في مهمة Blueprint. كل جدول يستخدم prefix `maa_slug_`، وكل جدول له `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`. كل FK هنا package-local؛ لا توجد FKs إلى Host.

### 12.1 `maa_slug_scopes`

```text
id              BIGINT UNSIGNED PK
namespace       VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
locale_key      VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''
context_key     VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''
profile_key     VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
created_at      DATETIME(6) NOT NULL
updated_at      DATETIME(6) NOT NULL
UNIQUE(namespace, locale_key, context_key)
INDEX(profile_key)
```

Scope profile لا يتغير بعد إنشاء الصف؛ لا يوجد update public لـ`profile_key`.

### 12.2 `maa_slug_bindings`

```text
id                   BIGINT UNSIGNED PK
scope_id             BIGINT UNSIGNED NOT NULL REFERENCES maa_slug_scopes(id) ON DELETE RESTRICT
entity_type          VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
entity_key           VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
current_registry_id  BIGINT UNSIGNED NULL
status               VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
revision             BIGINT UNSIGNED NOT NULL DEFAULT 0
history_sequence     BIGINT UNSIGNED NOT NULL DEFAULT 0
created_at           DATETIME(6) NOT NULL
updated_at           DATETIME(6) NOT NULL
UNIQUE(scope_id, entity_type, entity_key)
UNIQUE(id, scope_id)
INDEX(current_registry_id)
INDEX(status)
```

`current_registry_id` لا يملك FK عمدًا حتى لا تنشأ circular impossible insert مع Registry. سلامته تُفرض داخل package transaction وبـinvariant read check موثق في §14؛ لا تستخدم القراءة pointer غير المتحقق منه.

### 12.3 `maa_slug_registry`

```text
id              BIGINT UNSIGNED PK
scope_id        BIGINT UNSIGNED NOT NULL
binding_id      BIGINT UNSIGNED NOT NULL
slug            VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
claim_role      VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
claimed_at      DATETIME(6) NOT NULL
updated_at      DATETIME(6) NOT NULL
current_marker  BIGINT UNSIGNED GENERATED ALWAYS AS
                (CASE WHEN claim_role = 'CURRENT_CANONICAL' THEN binding_id ELSE NULL END) STORED
FOREIGN KEY(scope_id) REFERENCES maa_slug_scopes(id) ON DELETE RESTRICT
FOREIGN KEY(binding_id, scope_id) REFERENCES maa_slug_bindings(id, scope_id) ON DELETE RESTRICT
UNIQUE(scope_id, slug)
UNIQUE(binding_id, slug)
UNIQUE(current_marker)
CHECK(claim_role IN ('CURRENT_CANONICAL','HISTORICAL_CANONICAL','ACTIVE_ALIAS','RETIRED_ALIAS'))
INDEX(binding_id, claim_role)
INDEX(scope_id, claim_role, id)
```

لا يوجد registry row بحالة `RELEASED`; release يمحو live claim ويكتب snapshot في History. `current_marker` يضمن صف current واحدًا لكل Binding دون partial-index assumption.

### 12.4 `maa_slug_history`

```text
id                       BIGINT UNSIGNED PK
binding_id               BIGINT UNSIGNED NOT NULL REFERENCES maa_slug_bindings(id) ON DELETE RESTRICT
sequence_no              BIGINT UNSIGNED NOT NULL
event_type               VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
scope_namespace_snapshot VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
scope_locale_snapshot    VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
scope_context_snapshot   VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
entity_type_snapshot     VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
entity_key_snapshot      VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
slug_snapshot            VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
previous_slug_snapshot   VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
related_namespace        VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NULL
related_locale           VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
related_context          VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
related_entity_type      VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
related_entity_key       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
operation_id             BIGINT UNSIGNED NULL REFERENCES maa_slug_operations(id) ON DELETE RESTRICT
operation_key            CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
actor_key                VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
reason                   VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
correlation_key          VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
occurred_at              DATETIME(6) NOT NULL
UNIQUE(binding_id, sequence_no)
INDEX(binding_id, occurred_at, id)
INDEX(event_type, occurred_at, id)
CHECK(event_type IN (the RC1 event vocabulary in §21))
```

History لا تملك `idempotency_key` أو `request_fingerprint`؛ تلك evidence تشغيلية في جدول العمليات التالي. كل event ناتج عن عملية ذات idempotency يحمل `operation_id` و`operation_key`. لا يستخدم History JSON dump عامًا. `operation_key` واحد مشترك في حدثي transfer out/in، مع snapshots source/target في كل جهة.

### 12.5 `maa_slug_operations` و`maa_slug_operation_bindings`

تُحفظ نتيجة العملية خارج History في سجل evidence مستقل؛ لذلك لا تعتمد replay على current state أو على إعادة hydration من Registry بعد أن تتغير الحالة. الصف الوحيد للعملية يمثل العملية كاملة، وصفوف المشاركين تجعل lookup محددًا لكل Binding:

```text
maa_slug_operations
id                    BIGINT UNSIGNED PK
operation_key         CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
operation_type        VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
request_fingerprint   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
result_type           VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
result_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1
result_snapshot       JSON NOT NULL
status                VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
created_at            DATETIME(6) NOT NULL
completed_at          DATETIME(6) NOT NULL
UNIQUE(operation_key) CONSTRAINT uk_operation_key
INDEX(operation_type, created_at, id)
CHECK(status = 'COMMITTED')

maa_slug_operation_bindings
id                    BIGINT UNSIGNED PK
idempotency_key       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
operation_id          BIGINT UNSIGNED NOT NULL REFERENCES maa_slug_operations(id) ON DELETE RESTRICT
binding_id            BIGINT UNSIGNED NOT NULL REFERENCES maa_slug_bindings(id) ON DELETE RESTRICT
participant_role      VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
UNIQUE(binding_id, idempotency_key) CONSTRAINT uk_operation_binding_idempotency
UNIQUE(operation_id, binding_id) CONSTRAINT uk_operation_binding_pair
INDEX(operation_id, participant_role)
CHECK(participant_role IN ('SINGLE','SOURCE','TARGET'))
```

`idempotency_key` يُخزن مرة واحدة لكل Binding مشارك؛ العملية متعددة الـBinding تشترك في صف `maa_slug_operations` واحد وصف `SOURCE` و`TARGET` في جدول المشاركين. `request_fingerprint` هو lowercase hexadecimal SHA-256 بطول 64، و`operation_key` هو lowercase hexadecimal بطول 32. `result_snapshot` هو serialization canonical للـDTO العام بعد نجاح mutation، ويحتوي discriminator `result_type` و`result_schema_version` وجميع الحقول المحددة في §35. لا يجوز أن يكون snapshot ناقصًا أو يعاد تركيبه من الحالة الحالية.

لا يُلتزم صف `IN_PROGRESS`: ينشئ التنفيذ العملية والمشاركين ويكتب History وlive state ويملأ snapshot ثم يلتزمها كـ`COMMITTED` في نفس transaction؛ rollback يمحو كل evidence الجزئية. يحتفظ RC1 بالـoperation snapshot بلا انتهاء زمني ما دام صف العملية موجودًا؛ لا يوجد automatic time-based purge. عند purge تُزال participant rows الخاصة بالـBinding؛ يبقى operation evidence ما دام مشارك آخر يحتاجها، وتحذف العملية بعد حذف آخر participant. بذلك يكون purge هو الحد الصريح لانتهاء ضمان replay لذلك الـBinding.

## 13. Database equality وkeys

تستخدم Slug وopaque identity columns `utf8mb4_bin`، وnamespace/profile/status/role/event keys `ascii_bin`. لا تنفذ قاعدة البيانات accent folding أو case folding أو Unicode normalization. يسبق كل claim/lookup canonicalization على مستوى Profile؛ equality النهائية هي exact value الناتجة عنها.

القيود الملزمة:

| الاسم | الغرض |
|---|---|
| `uk_scope_identity` | منع Scope مكرر؛ profile خارج المفتاح |
| `uk_binding_identity` | Binding واحد لكل EntityReference داخل Scope |
| `uk_registry_scope_slug` | authority النهائية لـsame-slug claim race |
| `uk_registry_binding_slug` | منع claim duplicate لنفس Binding |
| `uk_registry_current_marker` | current canonical واحد لكل Binding |
| `uk_history_binding_sequence` | sequence ثابت لكل Binding |
| `uk_operation_key` | هوية عملية واحدة وsnapshot واحد قابل لإعادة القراءة |
| `uk_operation_binding_idempotency` | منع side-effect مكرر لكل Binding مشارك بالـidempotency key |
| `uk_operation_binding_pair` | يمنع تسجيل Binding مرتين في العملية نفسها |

كل unique/index name جزء من adapter contract؛ أي driver مستقبلي يحتاج equality وduplicate evidence وschema/index contract جديدًا، ولا يرث دعم MySQL تلقائيًا. `uk_operation_binding_idempotency` هي lookup authority للـreplay، و`uk_registry_scope_slug` تبقى authority لملكية slug الحية.

## 14. Scope bootstrap وcurrent-pointer lifecycle

عند أول استعمال:

1. يتحقق Service من Profile key وProfile behavior قبل mutation.
2. يبدأ package transaction أو savepoint حسب §26.
3. يبحث عن Scope بالـcanonical key ويقفل الصف. إذا لم توجد، يحاول insert بالـunique key؛ duplicate `1062` في هذا المسار فقط يعيد القراءة `FOR UPDATE`.
4. يقارن requested profile مع الصف. اختلافه يرمى `SlugScopeProfileMismatchException` قبل إنشاء أو تعديل Binding.
5. ينشئ Binding placeholder بـ`status=RELEASED`, `current_registry_id=NULL`, `revision=0`, `history_sequence=0` إن لم توجد.
6. يدرج Registry current بعد نجاح claim policy، ثم يحدث pointer وstatus في update واحد محمي بقفل الصف، ويرفع revision وhistory sequence، ثم يتحقق من pointer/role/binding/scope.
7. يكتب History ضمن نفس transaction ثم commit/release savepoint.

لا توجد لحظة تعتمد على FK دائري. `current_registry_id=NULL` هو الحالة الوحيدة الصحيحة للـplaceholder أو `RELEASED`. `ACTIVE` و`INACTIVE` يجب أن يملكا current pointer صالحًا؛ إذا وجد read أي pointer غير موجود أو role غير `CURRENT_CANONICAL` أو scope mismatch يرمى `SlugPersistenceInvariantException` ولا يستخدم fallback.

التنافس على first use: محاولتان بنفس profile تنتظران unique row lock وتستخدمان نفس Scope. profile mismatch لا ينتج سلوكًا race-dependent. محاولة Scope موجودة بـprofile آخر تفشل قبل Binding/Registry/History mutation.

## 15. Binding status transitions

القيم الوحيدة هي:

```text
ACTIVE    → INACTIVE أو RELEASED عبر العملية الصريحة المناسبة
INACTIVE  → ACTIVE أو RELEASED عبر العملية الصريحة المناسبة
RELEASED  → ACTIVE عند assign جديد فقط
```

- `ACTIVE`: current claim موجود ومتاح للـresolution.
- `INACTIVE`: current/history/alias claims ما زالت مملوكة، والـresolution يعيد binding inactive؛ لا تحوّل الحزمة ذلك إلى HTTP status.
- `RELEASED`: لا live Registry claims ولا current pointer؛ retained History قد يبقى. لا تقبل `reactivate` وحدها؛ assign جديد يعيدها إلى `ACTIVE`.

`deactivate` يغير status فقط ولا يحرر claim. `reactivate` يعمل من `INACTIVE` فقط ولا ينشئ slug. Generic status update غير موجود.

## 16. Registry roles وclaim rules

الأدوار الأربع الحية:

| role | المعنى | resolution |
|---|---|---|
| `CURRENT_CANONICAL` | current canonical الوحيد للـBinding | `CURRENT` |
| `HISTORICAL_CANONICAL` | canonical سابق ما زال محجوزًا | `HISTORICAL` |
| `ACTIVE_ALIAS` | alias بديل حي | `ALIAS` |
| `RETIRED_ALIAS` | alias متقاعد لكنه محجوز | `RETIRED_ALIAS` |

كل role scope-local. canonical change يحول previous current إلى historical، لا يمسحه. aliases لا تُنشئ صفًا ثانيًا لنفس `(binding,slug)`؛ role transition يحدث على الصف الموجود.

## 17. Exact claim وautomatic allocation

### 17.1 Exact

يطبق claim canonicalization فقط. إذا كانت القيمة مملوكة لـBinding نفسه، فـcurrent يعيد no-op، وhistorical أو alias/retired يرقّى وفق العملية المطلوبة. إذا كانت مملوكة لBinding آخر، يفشل بـ`SlugAlreadyClaimedException`. لا suffix بديل.

### 17.2 Generated

يولد base من source ثم يجرب base و`-2` ... `-1000`. Database unique هو الحكم النهائي. عند `1062` على slug constraint:

- exact operation يحولها إلى semantic conflict؛
- generated operation ينتقل إلى candidate التالي؛
- أي duplicate evidence غير مربوط بهذا constraint يعاد كما هو.

candidate المملوك لنفس Binding لا يعد collision: current يعيد no-op، وhistorical يرقى إلى current، وalias يطبق role transition المحدد. candidate المملوك لغيره يسبب suffix في generated mode فقط.

## 18. Availability

`checkAvailability` read-only، لا ينشئ Scope ولا Binding ولا History، ويستخدم Profile المتوقع. إذا لم يوجد Scope، يحسب النتيجة دون كتابة ويعامل الاسم غير الم reserved كـ`AVAILABLE`. إذا وجد Scope بProfile مختلف يفشل configuration mismatch.

`AvailabilityStatusEnum`:

```text
AVAILABLE
OWNED_BY_SAME_BINDING
OWNED_BY_OTHER_BINDING
RESERVED
INVALID
```

الفحص advisory فقط؛ لا يجوز للمستهلك تحويل `AVAILABLE` إلى ضمان. claim/allocation الحقيقي يعيد النتيجة authoritative بعد unique/transaction.

## 19. Lifecycle mutation contract

| العملية العامة | القاعدة |
|---|---|
| `assignExact` | Binding غير موجود أو `RELEASED` فقط؛ ينشئ/يعيد current exact؛ existing ACTIVE/INACTIVE يستخدم change |
| `assignGenerated` | مثل assignExact مع bounded suffix allocation |
| `changeExact` | Binding موجود ACTIVE/INACTIVE؛ يستبدل current ويحفظ السابق historical |
| `changeGenerated` | مثل changeExact مع same-binding restore وعدم suffix لنفس ownership |
| `restoreHistorical` | يعيد historical canonical لنفس Binding إلى current؛ لا يحرر ownership |
| `deactivate` | ACTIVE → INACTIVE، دون تغيير Registry |
| `reactivate` | INACTIVE → ACTIVE، مع current الموجود |
| `releaseClaim` | يحرر non-current claim محددًا فقط؛ current يرفض ويتطلب replacement أو releaseAll |
| `releaseAllOwnership` | يكتب snapshots لكل claim، يمحو كل Registry، يضع Binding RELEASED وpointer NULL |
| `transitionScope` | ينشئ target Binding؛ لا يغير scope_id للمصدر؛ MOVE يجعل المصدر INACTIVE، PARALLEL يبقيه كما هو |
| `atomicTransfer` | ينقل claim محددًا بين Bindingين في نفس Scope transaction واحدة |

كل mutation أحادي الـBinding يعيد `SlugMutationResultDTO` بالشكل الكامل المحدد في §35.4. `transitionScope` يعيد `ScopeTransitionResultDTO` مستقلًا، و`atomicTransfer` يعيد `AtomicTransferResultDTO` مستقلًا؛ لا يجوز اختزالهما إلى `SlugMutationResultDTO` لأنهما يغيران أكثر من Binding. adoption يعيد `AdoptionResultDTO`، و`purgeBinding` لا يعيد DTO لأنه يمحو الـBinding.

Natural no-op يعيد snapshot الحالة الحالية ولا يرفع revision أو يضيف event جديدًا. أما idempotency replay فيعيد snapshot النتيجة الأصلية المحفوظ في `maa_slug_operations.result_snapshot` حتى لو تغيّرت الحالة الحية بعد العملية الأصلية؛ لا يعاد بناؤه من current state.

## 20. Aliases

- `addAlias`: exact claim canonicalization، ينشئ `ACTIVE_ALIAS` أو يحول same-binding retained claim إلى alias وفق role rules؛ conflict مع Binding آخر يفشل.
- `retireAlias`: `ACTIVE_ALIAS → RETIRED_ALIAS`، لا يحرر ownership؛ تكراره no-op.
- `reactivateAlias`: `RETIRED_ALIAS → ACTIVE_ALIAS`، لا يحرر ownership ولا يفحص reservation على same-binding claim.
- `promoteAliasToCurrent`: `ACTIVE_ALIAS` فقط؛ previous current → historical، alias → current، pointer يتحول، وHistory يسجل promotion. لا يسمح promotion مباشرة من retired؛ يستلزم reactivate أولًا.

لا تعني alias/history redirect chain. كل resolution يقرأ Registry role ثم current pointer مباشرة.

## 21. History event vocabulary وsnapshots

القيم الملزمة لـ`HistoryEventTypeEnum` هي:

```text
ASSIGNED
CHANGED
RESTORED
ALIAS_ADDED
ALIAS_RETIRED
ALIAS_REACTIVATED
ALIAS_PROMOTED
DEACTIVATED
REACTIVATED
SCOPE_TRANSITIONED_OUT
SCOPE_TRANSITIONED_IN
OWNERSHIP_RELEASED
OWNERSHIP_RELEASED_ALL
OWNERSHIP_TRANSFERRED_OUT
OWNERSHIP_TRANSFERRED_IN
ADOPTED_CURRENT
ADOPTED_HISTORICAL
ADOPTED_ALIAS
```

كل row يحفظ sequence per binding وscope/entity/slug snapshots وoccurred_at وoptional actor/reason/correlation. `CHANGED` و`RESTORED` يملكان previous/current snapshots. release يملك slug snapshot قبل حذف Registry. reuse اللاحق ينتج event جديدًا للمالك الجديد ولا يعيد استخدام History القديم.

Transfer ينتج في transaction واحدة:

1. `OWNERSHIP_TRANSFERRED_OUT` مربوطًا بالمصدر مع slug وtarget scope/entity snapshots؛
2. `OWNERSHIP_TRANSFERRED_IN` مربوطًا بالهدف مع slug وsource snapshots؛
3. عند نقل current، event source replacement قبل transfer out، والهدف يصبح current حسب target role.

الحدثان يشتركان في `operation_key` random 32-character hex، وتسلسل كل Binding مستقل. لذلك يبقى كل طرف مفهومًا بعد release أو purge للطرف الآخر، ولا يخلط management بين retained evidence وlive ownership.

## 22. Same-binding restore/reuse

كل lifecycle operation تفحص Registry داخل Binding أولًا. إذا كان requested/generated candidate retained لنفس Binding:

- current: no-op؛
- historical: promote إلى current عند assign/change/restore؛
- active alias: promote عند lifecycle canonical operation؛
- retired alias: لا يتحول إلى canonical مباشرة؛ reactivate ثم promote صراحةً، باستثناء generated canonical path الذي يعامل retired same-binding claim كـrestore إلى current ويسجل `RESTORED`، ولا ينشئ suffix.

الـlast case هو contract محدد لـgenerated allocation، ولا يغير alias operation الصريح.

## 23. Scope transition

`TransitionScopeCommand` يحدد source Binding، target `SlugScope`، target expected profile، `ScopeTransitionModeEnum` (`MOVE` أو `PARALLEL`)، exact/generated target claim intent، source expected revision، وaudit/idempotency data.

- target scope هوية مختلفة؛ إذا تطابقت source والtarget يرمى invalid argument.
- target Profile يطبق على target slug؛ لا يعاد تفسير source slug دون canonicalization target.
- target Binding يجب ألا يوجد عند بدء العملية، إلا في idempotent replay المطابق.
- `PARALLEL`: target يصبح ACTIVE، source status لا يتغير؛ يسمح لنفس EntityReference بBindings نشطة متعددة في Scopes مختلفة.
- `MOVE`: target يصبح ACTIVE، source يصبح INACTIVE، source claims/history تبقى كما هي ولا يعاد كتابة scope_id أو registry scope.
- failure في target claim يلغي target/source changes معًا.
- transition ليس ownership transfer؛ اختلاف Scope وحده لا ينقل slug text ملكيةً بين bindings.

النتيجة ليست عامة: `ScopeTransitionResultDTO` يحفظ `operationKey`, `mode`, ولقطتي `sourceBefore/sourceAfter` و`targetBefore/targetAfter`، مع `targetCreated`, و`sourceRevision`, و`targetRevision`, و`sourceResult`, و`targetResult`, وقائمة History الناتجة و`replayed`. عند `PARALLEL` تكون source before/after نفس الحالة الدلالية مع revision المصدر دون زيادة، وعند `MOVE` يظهر source بعده `INACTIVE` مع revision جديد؛ target before هو `null` في التنفيذ الأول وtarget after هو Binding الجديد. replay يعيد aggregate الأصلي كاملًا.

## 24. Atomic cross-binding transfer

العقد العام هو `atomicTransfer(AtomicTransferCommand)`. المصدر والهدف يجب أن يكونا Bindingين مختلفين في **نفس Scope**. لا يدعم RC1 cross-scope transfer؛ ذلك `transitionScope`.

الأدوار القابلة للنقل هي الأدوار الأربع. القواعد الخاصة بـ`CURRENT_CANONICAL`:

- نقل current من المصدر يتطلب `sourceReplacementIntent` exact/generated؛ يعيّن replacement current للمصدر في نفس transaction قبل تحرير slug المنقول.
- target role `CURRENT_CANONICAL` يتطلب target `RELEASED`، أو target binding بلا current ضمن حالة bootstrap؛ يصبح target ACTIVE.
- target role غير current يتطلب target ACTIVE أو INACTIVE وله current قائم.
- نقل non-current لا يحتاج replacement للمصدر.

المصدر لا يكون RELEASED، والهدف لا يساوي المصدر. Reservation تفحص الهدف دائمًا عندما يكون target ownership جديدًا؛ existing same-target retained claim تعالج كـidempotent same-binding state فقط. أي conflict لBinding آخر يفشل ولا يستخدم release ضمني.

العملية تحذف source Registry row وتدرج target row تحت نفس transaction، مع History out/in وrevision لكل طرف. لذلك لا يرى قارئ ملتزم حالة unowned gap، ويظل unique `scope_id+slug` مانعًا لأي منافس. replay مع نفس idempotency key يعيد النتيجة السابقة؛ من دون key لا يوجد exactly-once guarantee.

النتيجة هي `AtomicTransferResultDTO` لا `SlugMutationResultDTO`: تحتوي `operationKey`, و`sourceBefore/sourceAfter`, و`targetBefore/targetAfter`, و`transferredClaim` (role وslug وscope/entity snapshots)، و`sourceReplacementResult` عند نقل current، و`sourceRevision`, و`targetRevision`, وقائمة حدثي out/in وجميع أحداث replacement و`replayed`. لا يجوز أن يضطر المستهلك إلى استنتاج أي state أو revision من query لاحقة.

## 25. Release وdestructive purge

### 25.1 `releaseClaim`

يقبل claim محددًا بالـslug أو claim identity بعد canonicalization، ويحرر non-current فقط. يقرأ snapshot، يحذف Registry، يرفع binding revision، ويسجل `OWNERSHIP_RELEASED`. إعادة claim لاحقة ممكنة لBinding آخر، ولا تعتبر History القديمة live resolution.

محاولة تحرير current ترفض بـ`SlugCurrentClaimReleaseException` قبل mutation؛ يجب أن يستخدم Host `change*`/replacement أو `releaseAllOwnership`.

### 25.2 `releaseAllOwnership`

يأخذ Binding ACTIVE أو INACTIVE مع expected revision، يكتب `OWNERSHIP_RELEASED_ALL` marker وأحداث `OWNERSHIP_RELEASED` لكل claim snapshot، يحذف Registry كلها، يضع pointer NULL وstatus RELEASED، ويرفع revision مرة واحدة. كل ذلك atomic. لا يمحو Binding أو History.

### 25.3 `purgeBinding`

عملية maintenance منفصلة ومدمرة. لا تعمل إلا على Binding `RELEASED` بلا Registry claims؛ وإلا `SlugPurgeNotPermittedException`. داخل transaction تحذف History ثم صفوف `maa_slug_operation_bindings` الخاصة بالـBinding ثم Binding، وتبقي Scope/profile لأنهما ليسا ownership لEntity. إذا بقي participant لBinding آخر، يبقى صف العملية وresult snapshot؛ وإذا لم يبق participant تحذف العملية. لا يتحقق package من Host entity أو privacy policy؛ Host يمنح التفويض. بعد purge لا تبقى replay/idempotency evidence لهذا Binding، ويصبح replay المعتمد على تلك evidence غير مضمون.

## 26. Transactions وsavepoints

كل mutation متعدد الجداول atomic.

### 26.1 لا توجد outer transaction

الحزمة تملك `BEGIN/COMMIT`. عند أي Throwable، إذا كان PDO ما زال داخل transaction تحاول rollback ثم تعيد original Throwable، أو semantic exception موثقًا يحتفظ بـ`previous`. لا swallow ولا catch عام في Services.

### 26.2 توجد outer transaction

لا تلتزم الحزمة أو تعمل rollback للـHost transaction. تنشئ savepoint باسم داخلي مولد من `[A-F0-9]{32}` مسبوق بـ`maa_slug_sp_`، قبل أي mutation. النجاح `RELEASE SAVEPOINT`; الفشل `ROLLBACK TO SAVEPOINT` ثم release إن أمكن، ثم rethrow. إذا فشل إنشاء savepoint أو كان driver لا يضمن semantics هذه، يفشل operation قبل mutation بـ`SlugTransactionParticipationException`.

Host هو الذي يقرر outer commit/rollback. تحقق Integration يثبت أن catch في Host بعد package exception لا يترك partial slug state، وأن Host rollback يمحو package changes كما يتوقع transaction ownership.

## 27. Locking وconcurrency

كل mutation يطبق lock order موحدًا:

1. canonicalize/validate كل المدخلات وresolve profiles قبل الكتابة؛
2. lock Scopes المطلوبة بترتيب binary `(namespace, locale_key, context_key)`؛
3. lock Bindings المطلوبة بترتيب `(scope_id, entity_type, entity_key, id)`؛
4. lock Registry claims/candidate keys بترتيب `(scope_id, slug)`؛
5. update/CAS للمراجعات وpointer/status؛
6. append History sequences؛
7. commit/release savepoint.

Transfer يقفل source/target Scope ثم source/target Binding وفق هذا الترتيب، حتى إن اختلف ترتيب المدخلات. Transition يقفل source/target Scopes ثم source Binding، ويثبت target unique creation. First-use يستخدم Scope unique lock path في §14.

الحماية:

- same-slug race: `UNIQUE(scope_id,slug)`؛ exact loser conflict، generated loser candidate next.
- same-binding race: lock row + `expectedRevision` CAS؛ stale writer `SlugRevisionConflictException` ولا overwrite.
- multi-binding race: locks لكل affected Binding + unique Registry + atomic transaction.

لا ينفذ RC1 automatic deadlock retry. deadlock/lock timeout infrastructure failures تعاد، ويعيد Host المحاولة عند الحاجة باستخدام نفس idempotency key. retry candidate في generated allocation وحده محدود بـ1000.

## 28. Revision وCAS

Revision يبدأ `0` ويرتفع مرة واحدة في كل successful state mutation لكل Binding. Natural no-op وidempotent replay لا يرفعانه. كل mutation على Binding موجود يتطلب `expectedRevision` مساويًا للقيمة المقروءة؛ assign الأول وtarget bootstrap يقبلان `null` لأن Binding غير موجود.

Transfer يتطلب source وtarget expected revisions عندما يكونان موجودين، ويرفع كل طرف مرة واحدة. Transition يتطلب source revision ويفرض target absent. فشل CAS أو اختلاف revision يرمى `SlugRevisionConflictException` قبل أي committed partial state.

## 29. Replay وidempotency

`correlationKey` audit-only. RC1 يضيف `?string $idempotencyKey` داخل `AuditContextDTO` إلى كل mutation command القابل للـreplay؛ PurgeBindingCommand يستلزم null لأن نجاحه يمحو evidence. عند وجوده، يحسب package lowercase SHA-256 `requestFingerprint` من canonical serialization ثابتة تشمل operation name، canonical inputs، expected profile، intent، expected revisions، ونسخة contract. تحفظ evidence في `maa_slug_operations` و`maa_slug_operation_bindings` (§12.5)، لا في current Registry ولا في History وحدها.

آلية التنفيذ الملزمة:

1. canonicalize/validate المدخلات قبل lookup؛ ثم يحسب التنفيذ fingerprint ويميز operation type.
2. مع key، يبحث في `maa_slug_operation_bindings` عن كل Binding متأثر بالـoperation. إذا وجد صفًا، يقرأ operation مرة واحدة ويشترط تطابق `request_fingerprint`, `operation_type`, المشاركين، و`COMMITTED`؛ الاختلاف يرمى `SlugIdempotencyConflictException`.
3. عند التطابق يعيد decoder النتيجة من `result_type + result_schema_version + result_snapshot` إلى DTO المحدد في §35، مع `replayed = true`. لا يقرأ current state لإعادة تشكيل النتيجة ولا يرفع revision ولا يكتب History.
4. عند غياب key يطبق التنفيذ natural state rules فقط؛ لا يوجد exactly-once guarantee بعد timeout.
5. عند غياب evidence ينشئ `operation_key` وصف العملية وصفوف `SINGLE` أو `SOURCE`/`TARGET` داخل نفس transaction، وينفذ mutation، ويبني aggregate من before/after snapshots، ثم يكتب snapshot canonical ويثبت العملية `COMMITTED` قبل commit.
6. إذا سباق تنفيذين على نفس key سبب unique conflict، يعيد التنفيذ قراءة الصف الملتزم: fingerprint المطابق replay، والمختلف conflict. لا يسمح هذا المسار بإعادة mutation.

لـ`transitionScope` يحفظ operation صفًا واحدًا مع مشاركي source وtarget؛ و`atomicTransfer` يحفظ source وtarget بالطريقة نفسها، مع `operation_key` واحد وsnapshot aggregate يضم source/target states/revisions/results. أما mutation الأحادي فيستخدم `SINGLE`. Reservation وrevision checks لا تُتجاوز بإعادة التشغيل، و`replayed` جزء من DTO المعاد ولا يغير الـsnapshot الأصلي.

operation snapshot retained indefinitely while its operation row exists؛ لا يوجد automatic time-based purge ولا يجوز حذف evidence ما دام Binding participant حيًا. maintenance purge يحذف participant rows للـBinding بعد History وBinding وفق §25.3؛ إذا بقي participant لBinding آخر يبقى operation snapshot، وإذا لم يبق أي participant تحذف العملية. بعد ذلك فقط ينتهي ضمان replay لذلك الـBinding، ويجب أن يوثق Host أن purge حد تدميري لا retry contract. أي عملية ذات key ملتزمة يجب أن تكون قابلة للقراءة كاملًا حتى purge، حتى لو تغيرت live state أو أصبحت claims released.

## 30. Resolution

`resolve` يأخذ Scope وprofile expected وdecoded segment. valid lookup canonicalization ثم exact Registry lookup. لا redirect chain.

`MatchKindEnum`:

```text
CURRENT, ALIAS, HISTORICAL, RETIRED_ALIAS, NONE
```

`BindingStatusEnum` القيم `ACTIVE`, `INACTIVE`, `RELEASED`; resolution الحية لا تعيد RELEASED لأن Registry claims RELEASED غير موجودة.

`InputFormCanonicalityEnum`:

```text
CANONICAL, NON_CANONICAL, INVALID, NOT_APPLICABLE
```

عند match، `CANONICAL/NON_CANONICAL` تقارن segment الأصلي بقيمة lookup canonical. عند invalid يعيد `NONE + INVALID` دون DB write. عند valid no-match يعيد `NONE + NOT_APPLICABLE`. DTO يحتوي requestedSegment وlookupCanonicalSlug عند صلاحيتها وmatchedSlug وmatchKind وbindingStatus وcurrentSlug وEntityReference وScope وrevision. Alias exact قد يكون `ALIAS + CANONICAL`؛ canonicality لا تعني primary target.

Inactive binding يبقى قابلًا للمطابقة ويفيد Host بأن status inactive؛ Host وحده يقرر 404/410/redirect/other.

## 31. Adoption

توجد public commands `adoptCurrent`, `adoptHistorical`, `adoptAlias`. كل واحدة تتطلب `BindingIdentityDTO`، وslug exact candidate، و`originalOccurredAt` اختياريًا، و`AuditContextDTO` مع idempotency عند الحاجة. لا توجد دلالة ضمنية على الحالة الحالية؛ القواعد الآتية ملزمة:

| العملية | Binding غير موجود | Binding `RELEASED` | Binding `ACTIVE` أو `INACTIVE` | Registry role والحالة الناتجة |
|---|---|---|---|---|
| `adoptCurrent` | ينشئ Binding revision `0` ثم current؛ لا current سابق | يعيد فتح Binding بلا claims؛ لا يعيد كتابة تاريخ قديم | يرفض إذا كان له current؛ لا يجوز استبدال current بالـadoption | `CURRENT_CANONICAL`، status `ACTIVE`، revision `1` (أو revision السابق + 1 عند reopen)، event `ADOPTED_CURRENT` |
| `adoptHistorical` | يرفض؛ لا يمكن إنشاء historical بلا current anchor | يرفض؛ يجب أولًا adoption لـcurrent | يقبل فقط إذا كان له current؛ يضيف claim تاريخيًا | `HISTORICAL_CANONICAL`، status لا يتغير، revision + 1، event `ADOPTED_HISTORICAL` |
| `adoptAlias` | يرفض؛ alias يتطلب Binding قائمًا وcurrent | يرفض؛ RELEASED لا يملك alias حيًا | يقبل فقط إذا كان له current؛ يضيف alias | `ACTIVE_ALIAS`، status لا يتغير، revision + 1، event `ADOPTED_ALIAS` |

في `adoptCurrent` لا يقبل التنفيذ `expectedRevision` إلا `null` لـBinding غير الموجود أو `RELEASED`. في `adoptHistorical` و`adoptAlias` يجب أن يساوي `expectedRevision` revision المقروء للـBinding الموجود. كل عملية تتحقق من canonical claim/profile، uniqueness، reservation عند إنشاء ownership جديدة، وownership الحالية لنفس Binding؛ conflict مع Binding آخر يرفض ولا يحرر ownership ضمنيًا. replay المطابق هو الاستثناء الوحيد للسماح بوجود نفس النتيجة السابقة، ويعيد `AdoptionResultDTO` المحفوظ.

التاريخ المستورد (`originalOccurredAt`) يجب أن يكون UTC-aware `DateTimeImmutable` ويكتب بدقة microsecond؛ لا يقبل تاريخًا نصيًا overflow أو timezone غير محدد. إذا كان `null`، يستخدم Clock وقت adoption. Registry هي مصدر ownership الحية، وHistory تسجل snapshot الحدث، وBinding revision يرتفع كما في الجدول. legacy semantics المختلفة تتطلب custom/legacy versioned Profile أو mapping صريح قبل الإدخال. لا يوجد generic ETL أو bulk/dry-run API في RC1؛ يكرر Host command ضمن transaction مناسبة.

## 32. Management queries وpagination

`SlugManagementQueryInterface` يقدم PHP contracts لا HTTP:

```text
getBinding(BindingCriteria): ?BindingDTO
getCurrent(CurrentSlugCriteria): ?CurrentSlugDTO
listAliases(AliasCriteria): AliasCollectionDTO
getHistory(HistoryCriteria): HistoryPageDTO
inspectRegistry(RegistryCriteria): RegistryPageDTO
inspectScope(ScopeCriteria): ?ScopeDTO
searchBindings(BindingSearchCriteria): BindingPageDTO
searchRegistry(RegistrySearchCriteria): RegistryPageDTO
```

Criteria `final readonly` وتتحقق من page default `1` وperPage default `25` وحد أقصى `100`، والـsort field enum whitelist. كل data/count query تشترك في security/Scope predicates؛ ترتيب deterministic مع `id ASC` tie-breaker. Management query لا تعيد unbounded set.

mechanics pagination (normalization/count/offset/metadata) delegated إلى stable `Maatify\\Persistence\\Pdo\\Pagination` من `maatify/persistence ^1.1`. Package يملك filters/search/cardinality/hydration، ولا ينسخ paginator. `PdoPaginator` لا يملك transaction؛ caller package transaction وحدها إن احتاجت.

Management يفصل live Registry عن History released/reused. لا يحول Host هذه الاستعلامات إلى generic filters أو Admin UI.

## 33. Clock وaudit

Runtime dependency هو `maatify/shared-common ^1.0` واستخدام `Maatify\\SharedCommon\\Contracts\\ClockInterface` المنشور. لا ينشئ package local Clock. كل `occurred_at`, `claimed_at`, `created_at`, `updated_at` الناتج عن Runtime يأتي من Clock، يحول إلى UTC ويخزن `DATETIME(6)`. لا تستخدم DB `CURRENT_TIMESTAMP` كbehavioral source.

`actorKey`, `reason`, `correlationKey` opaque اختيارية وتطبق validation limits §9؛ لا يفهم package user/auth tables أو request objects. `reason` ليس command behavior ولا idempotency. لا تغير الحزمة PHP global timezone.

## 34. Dependencies وconstruction

RC1 runtime contracts المباشرة:

| Dependency | الحد الأدنى | السبب |
|---|---:|---|
| PHP | `^8.4` | baseline new Maatify library |
| `ext-intl` | `*` | NFC وICU transliteration |
| `ext-mbstring` | `*` | Unicode case/length operations |
| `ext-pdo` | `*` | PDO contract |
| `ext-pdo_mysql` | `*` | MySQL adapter الوحيد |
| `maatify/exceptions` | `^1.0` | hierarchy and semantic exception base |
| `maatify/shared-common` | `^1.0` | `ClockInterface` |
| `maatify/persistence` | `^1.1` | stable pagination API |

هذه minimum versions هي الإصدارات المستقرة التي يذكرها Standards baseline للواجهات المستخدمة. لا تعتمد الحزمة على transitive dependency أو ambient extension. `composer.json` لاحقًا يجب أن يصرح بها مباشرة؛ لا ينشأ هنا.

construction public يكون عبر `SlugEngineFactory` final framework-neutral ويستقبل `PDO`, `SlugProfileRegistryInterface`, `ReservedSlugPolicyInterface`, و`ClockInterface`. لا يقرأ `.env` ولا ينشئ Host connection ولا يستخدم Service Locator. built-in profiles تسجل تلقائيًا، وcustom profiles تسجل صراحةً قبل lifecycle use.

## 35. Public commands وcriteria contract

### 35.1 العقد العام الكامل وقواعد التحقق

هذا القسم هو العقد التنفيذي المقفول لـRC1، وليس مجرد inventory أسماء. كل type هنا تحت namespace Maatify\\Slug\\، وكل Command وCriteria وDTO هو final readonly، وكل DTO يطبق JsonSerializable. الـconstructor signatures التالية هي الـpublic constructors الوحيدة؛ لا يضيف التنفيذ fields أو overloads أو raw arrays بدل الأنواع المحددة.

الأسماء الخارجية في signatures لها imports ثابتة: `PDO` تعني `\\PDO`، و`DateTimeImmutable` تعني `\\DateTimeImmutable`، و`JsonSerializable` تعني `\\JsonSerializable`، و`Throwable` تعني `\\Throwable`، و`ClockInterface` تعني `\\Maatify\\SharedCommon\\Contracts\\ClockInterface`.

الأنواع الأساسية:

    final readonly class Slug
    {
        public function __construct(public string $value) {}
    }

    final readonly class SlugProfileKey
    {
        public function __construct(public string $value) {}
    }

    final readonly class SlugScope
    {
        public function __construct(
            public string $namespace,
            public ?string $localeKey,
            public ?string $contextKey,
        ) {}
    }

    final readonly class EntityReference
    {
        public function __construct(
            public string $entityType,
            public string $entityKey,
        ) {}
    }

Slug يرفض value غير canonical؛ SlugProfileKey يطبق regex §6.2؛ SlugScope وEntityReference يطبقان حدود §9، مع رفض string الفارغ الصريح وترك null locale/context فقط كغياب dimension. لا يطبق constructor trim أو case folding أو normalization على identity.

    final readonly class AuditContextDTO implements JsonSerializable
    {
        public function __construct(
            public ?string $actorKey = null,
            public ?string $reason = null,
            public ?string $correlationKey = null,
            public ?string $idempotencyKey = null,
        ) {}
    }

كل حقل Audit اختياري ويطبق limits §9. idempotencyKey non-empty وUTF-8 وبحد 191 code points، وcorrelationKey لا يدخل fingerprint. وقت الحدث يأتي من Clock، وليس من AuditContextDTO.

    final readonly class ScopeProfileRequestDTO implements JsonSerializable
    {
        public function __construct(
            public SlugScope $scope,
            public SlugProfileKey $expectedProfileKey,
        ) {}
    }

يمثل هذا DTO pairing المطلوب؛ يجب أن يكون profile مسجلًا، واختلافه عن Profile المحفوظة يفشل قبل أي write. ويستعمل في كل Binding identity وفي Scope reads/writes.

    final readonly class BindingIdentityDTO implements JsonSerializable
    {
        public function __construct(
            public ScopeProfileRequestDTO $scopeProfile,
            public EntityReference $entity,
        ) {}
    }

    final readonly class ScopeTransitionClaimIntentDTO implements JsonSerializable
    {
        public function __construct(
            public ClaimIntentModeEnum $mode,
            public string $value,
        ) {}
    }

    final readonly class TransferReplacementIntentDTO implements JsonSerializable
    {
        public function __construct(
            public ClaimIntentModeEnum $mode,
            public string $value,
        ) {}
    }

في intent يكون value source text عند GENERATED وexact candidate عند EXACT؛ لا يفسر التنفيذ القيمة عكس mode. expectedRevision هو ?int فقط في commands التي تسمح بBinding absent/released، وغير سالب دائمًا؛ int في بقية commands ومطابق لقيمة الصف المقفول.

### 35.2 Commands

كل signature آتٍ هو constructor كامل، والحقول public readonly:

| Command | exact constructor signature | validation والدلالة |
|---|---|---|
| AssignExactCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, ?int $expectedRevision, AuditContextDTO $audit) | exact candidate يمر canonicalizeClaim؛ null revision مسموح فقط عند absent/RELEASED، وint عند existing ACTIVE/INACTIVE؛ ينشئ أو يعيد current. |
| AssignGeneratedCommand | __construct(BindingIdentityDTO $binding, string $sourceText, ?int $expectedRevision, AuditContextDTO $audit) | source غير فارغ؛ generation bounded إلى base ثم -2..-1000؛ نفس preconditions والحالة. |
| ChangeExactCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | Binding ACTIVE/INACTIVE وله current؛ يستبدل current ويحفظ السابق historical. |
| ChangeGeneratedCommand | __construct(BindingIdentityDTO $binding, string $sourceText, int $expectedRevision, AuditContextDTO $audit) | مثل ChangeExact مع generated candidate وsame-binding restore. |
| RestoreHistoricalCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | requested slug historical لنفس Binding؛ يرقّيه إلى current ولا ينقل ownership. |
| DeactivateBindingCommand | __construct(BindingIdentityDTO $binding, int $expectedRevision, AuditContextDTO $audit) | ACTIVE فقط؛ لا يغير Registry claims. |
| ReactivateBindingCommand | __construct(BindingIdentityDTO $binding, int $expectedRevision, AuditContextDTO $audit) | INACTIVE وله current؛ يعيده ACTIVE. |
| ReleaseClaimCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | يحرر non-current claim محددًا؛ current يرمى SlugCurrentClaimReleaseException. |
| ReleaseAllOwnershipCommand | __construct(BindingIdentityDTO $binding, int $expectedRevision, AuditContextDTO $audit) | ACTIVE/INACTIVE؛ يكتب snapshots، يمحو Registry، يضع RELEASED. |
| TransitionScopeCommand | __construct(BindingIdentityDTO $source, ScopeProfileRequestDTO $targetScope, ScopeTransitionModeEnum $mode, ScopeTransitionClaimIntentDTO $targetClaimIntent, int $sourceExpectedRevision, AuditContextDTO $audit) | source موجود وtarget identity مختلف وtarget Binding absent؛ ينشئ target ويطبق MOVE/PARALLEL. |
| AtomicTransferCommand | __construct(BindingIdentityDTO $source, BindingIdentityDTO $target, string $slugCandidate, ?TransferReplacementIntentDTO $sourceReplacementIntent, int $sourceExpectedRevision, int $targetExpectedRevision, AuditContextDTO $audit) | source/target مختلفان في نفس Scope؛ replacement مطلوب فقط لنقل current؛ target Binding موجود أو replay مطابق. |
| AddAliasCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | ACTIVE/INACTIVE وله current؛ ينشئ ACTIVE_ALIAS أو يعالج same-binding retained claim. |
| RetireAliasCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | ACTIVE_ALIAS فقط؛ يحوله RETIRED_ALIAS دون release. |
| ReactivateAliasCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | RETIRED_ALIAS فقط؛ يعيده ACTIVE_ALIAS. |
| PromoteAliasToCurrentCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, int $expectedRevision, AuditContextDTO $audit) | ACTIVE_ALIAS فقط؛ previous current historical والalias current. |
| AdoptCurrentCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, ?DateTimeImmutable $originalOccurredAt, ?int $expectedRevision, AuditContextDTO $audit) | Binding absent/RELEASED فقط، revision null في هاتين الحالتين؛ ينشئ CURRENT_CANONICAL وACTIVE، event ADOPTED_CURRENT. |
| AdoptHistoricalCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, ?DateTimeImmutable $originalOccurredAt, int $expectedRevision, AuditContextDTO $audit) | Binding ACTIVE/INACTIVE وله current؛ يضيف HISTORICAL_CANONICAL، ولا يسمح absent/RELEASED؛ null timestamp يستخدم Clock. |
| AdoptAliasCommand | __construct(BindingIdentityDTO $binding, string $slugCandidate, ?DateTimeImmutable $originalOccurredAt, int $expectedRevision, AuditContextDTO $audit) | Binding ACTIVE/INACTIVE وله current؛ يضيف ACTIVE_ALIAS، ولا يسمح absent/RELEASED؛ null timestamp يستخدم Clock. |
| PurgeBindingCommand | __construct(BindingIdentityDTO $binding, int $expectedRevision, AuditContextDTO $audit) | RELEASED بلا claims فقط؛ audit.idempotencyKey يجب أن يكون null لأن purge يمحو evidence ويُنهي replay guarantee؛ النتيجة void. |

كل candidate/source يرفض security-invalid input ويمر بالـProfile الصحيح. كل command mutation قابل للـreplay، ما عدا PurgeBindingCommand المعلن صراحةً كعملية destructive غير قابلة للـreplay بعد نجاحها.

### 35.3 Criteria

| Criteria | exact constructor signature | الحقول والتحقق |
|---|---|---|
| AvailabilityCriteria | __construct(ScopeProfileRequestDTO $scopeProfile, string $candidate, ?BindingIdentityDTO $requestingBinding = null) | candidate غير فارغ؛ requestingBinding اختياري لتصنيف OWNED_BY_SAME_BINDING، وإلا تكون الملكية الأخرى OWNED_BY_OTHER_BINDING؛ النتيجة advisory وتصنف INVALID أو ownership أو RESERVED أو AVAILABLE. |
| ResolutionCriteria | __construct(ScopeProfileRequestDTO $scopeProfile, string $decodedSegment) | decoded segment فقط، لا percent-encoding؛ invalid يعاد في DTO كـINVALID. |
| BindingCriteria | __construct(BindingIdentityDTO $binding) | lookup exact لـScope + EntityReference. |
| CurrentSlugCriteria | __construct(BindingIdentityDTO $binding) | يقرأ current الحي فقط، ويعيد null عند غيابه. |
| AliasCriteria | __construct(BindingIdentityDTO $binding, int $page = 1, int $perPage = 25, AliasSortFieldEnum $sort = AliasSortFieldEnum::ID, SortDirectionEnum $direction = SortDirectionEnum::ASC) | page >= 1، perPage 1..100؛ الدور محصور في alias rows. |
| HistoryCriteria | __construct(BindingIdentityDTO $binding, ?HistoryEventTypeEnum $eventType = null, int $page = 1, int $perPage = 25, SortDirectionEnum $direction = SortDirectionEnum::ASC) | event filter اختياري؛ ترتيب occurred_at ثم id؛ page limits ثابتة. |
| RegistryCriteria | __construct(ScopeProfileRequestDTO $scopeProfile, ?BindingIdentityDTO $binding = null, ?RegistryRoleEnum $role = null, int $page = 1, int $perPage = 25, RegistrySortFieldEnum $sort = RegistrySortFieldEnum::ID, SortDirectionEnum $direction = SortDirectionEnum::ASC) | binding إن وجد داخل نفس scope؛ role allowlist؛ page limits. |
| ScopeCriteria | __construct(ScopeProfileRequestDTO $scopeProfile) | يثبت expected profile أثناء القراءة؛ لا ينشئ Scope. |
| BindingSearchCriteria | __construct(ScopeProfileRequestDTO $scopeProfile, ?string $entityType = null, ?string $entityKeyPrefix = null, ?BindingStatusEnum $status = null, int $page = 1, int $perPage = 25, BindingSortFieldEnum $sort = BindingSortFieldEnum::ID, SortDirectionEnum $direction = SortDirectionEnum::ASC) | search strings opaque، limits §9؛ filters لا تتجاوز scope predicate. |
| RegistrySearchCriteria | __construct(ScopeProfileRequestDTO $scopeProfile, ?string $slugPrefix = null, ?RegistryRoleEnum $role = null, ?BindingStatusEnum $bindingStatus = null, int $page = 1, int $perPage = 25, RegistrySortFieldEnum $sort = RegistrySortFieldEnum::ID, SortDirectionEnum $direction = SortDirectionEnum::ASC) | prefix valid UTF-8؛ query لا تعيد unbounded rows. |

لا يستخدم Criteria كـresult DTO، ولا يستخدم DTO كـexecution intent. كل count query وdata query يطبقان predicates نفسها، مع id ASC كـtie-breaker.

### 35.4 DTOs العامة وحقولها

الحقول التالية public readonly، والـnullable كما هو موضح:

| DTO | exact fields |
|---|---|
| GeneratedSlugDTO | SlugProfileKey $profileKey، string $source، Slug $slug؛ source محفوظ لأغراض العرض ولا يعاد تفسيره. |
| CanonicalSlugDTO | SlugProfileKey $profileKey، string $input، Slug $slug. |
| LookupCanonicalizationDTO | SlugProfileKey $profileKey، string $decodedSegment، InputFormCanonicalityEnum $canonicality، ?Slug $canonicalSlug؛ canonicalSlug null عند INVALID. |
| ScopeDTO | int $id، SlugScope $scope، SlugProfileKey $profileKey، DateTimeImmutable $createdAt، DateTimeImmutable $updatedAt. |
| BindingStateDTO | BindingStatusEnum $status، ?Slug $currentSlug، int $revision، int $historySequence. |
| BindingDTO | int $id، BindingIdentityDTO $identity، BindingStateDTO $state، ?RegistryClaimDTO $currentClaim، DateTimeImmutable $createdAt، DateTimeImmutable $updatedAt. |
| RegistryClaimDTO | int $id، BindingIdentityDTO $binding، Slug $slug، RegistryRoleEnum $role، DateTimeImmutable $claimedAt، DateTimeImmutable $updatedAt. |
| CurrentSlugDTO | BindingDTO $binding، RegistryClaimDTO $claim، int $revision. |
| AliasDTO | RegistryClaimDTO $claim، bool $resolvableAsAlias، int $bindingRevision. |
| HistoryEventDTO | int $id، int $bindingId، int $sequenceNo، HistoryEventTypeEnum $eventType، SlugScope $scopeSnapshot، EntityReference $entitySnapshot، ?Slug $slugSnapshot، ?Slug $previousSlugSnapshot، ?SlugScope $relatedScope، ?EntityReference $relatedEntity، ?string $operationKey، ?string $actorKey، ?string $reason، ?string $correlationKey، DateTimeImmutable $occurredAt. |
| SlugAvailabilityDTO | ScopeProfileRequestDTO $scopeProfile، string $requestedInput، ?Slug $canonicalSlug، AvailabilityStatusEnum $status، ?BindingDTO $owner، bool $advisory؛ advisory دائمًا true في RC1. |
| SlugResolutionDTO | ScopeProfileRequestDTO $scopeProfile، string $requestedSegment، InputFormCanonicalityEnum $inputCanonicality، ?Slug $lookupCanonicalSlug، ?Slug $matchedSlug، MatchKindEnum $matchKind، ?BindingStatusEnum $bindingStatus، ?Slug $currentSlug، ?EntityReference $entity، ?int $bindingRevision. حالات NONE/INVALID تجعل الحقول غير المنطبقة null. |
| SlugMutationResultDTO | OperationTypeEnum $operationType، string $operationKey، bool $replayed، ?BindingDTO $before، BindingDTO $after، list<RegistryClaimDTO> $affectedClaims، ?Slug $previousSlug، ?Slug $currentSlug، ChangeTypeEnum $changeType، int $revision، list<HistoryEventDTO> $historyEvents. affectedClaims هي live claims الناتجة؛ تكون فارغة عند releaseAll، وتبقى snapshots لكل claim محذوف في historyEvents. |
| BindingStateResultDTO | ?BindingDTO $before، BindingDTO $after، bool $mutated، int $revision، list<HistoryEventDTO> $historyEvents. يستعمل participant result في aggregates. |
| ScopeTransitionResultDTO | OperationTypeEnum $operationType، string $operationKey، bool $replayed، ScopeTransitionModeEnum $mode، bool $targetCreated، BindingStateResultDTO $sourceResult، BindingStateResultDTO $targetResult، ?BindingDTO $sourceBefore، BindingDTO $sourceAfter، ?BindingDTO $targetBefore، BindingDTO $targetAfter، ?Slug $sourceClaim، ?Slug $targetClaim، int $sourceRevision، int $targetRevision، list<HistoryEventDTO> $historyEvents. sourceResult وtargetResult موجودان دائمًا؛ targetBefore null في التنفيذ الأول وtargetCreated يوضح إنشاء target في هذه العملية. |
| AtomicTransferResultDTO | OperationTypeEnum $operationType، string $operationKey، bool $replayed، BindingStateResultDTO $sourceResult، BindingStateResultDTO $targetResult، RegistryClaimDTO $transferredClaim، ?SlugMutationResultDTO $sourceReplacementResult، int $sourceRevision، int $targetRevision، list<HistoryEventDTO> $historyEvents. |
| AdoptionResultDTO | OperationTypeEnum $operationType، string $operationKey، bool $replayed، ?BindingDTO $before، BindingDTO $after، RegistryClaimDTO $adoptedClaim، HistoryEventDTO $historyEvent. |
| BindingPageDTO | list<BindingDTO> $items، int $total، int $page، int $perPage. |
| RegistryPageDTO | list<RegistryClaimDTO> $items، int $total، int $page، int $perPage. |
| HistoryPageDTO | list<HistoryEventDTO> $items، int $total، int $page، int $perPage. |
| AliasCollectionDTO | list<AliasDTO> $items، int $total، int $page، int $perPage. |

كل list هي PHP array متجانسة موثقة بـlist<T>، وكل total غير سالب، وكل page/perPage يطابق Criteria. Json serialization تعرض هذه الحقول فقط ولا تعرض PDO أو Host records أو SQL state.

قواعد DTO الحافظة للاتساق: BindingStateDTO بحالة ACTIVE أو INACTIVE يجب أن يحمل currentSlug، وBindingDTO المقابل يجب أن يحمل currentClaim؛ حالة RELEASED يجب أن تحمل الاثنين كـnull. operationKey في نتائج mutations هو lowercase hex بطول 32، وhistoryEvents مرتبة حسب sequence داخل كل Binding. `replayed = true` لا يصاحبه أي event جديد ولا revision جديد، وsource/target participant results في transition/transfer موجودة دائمًا حتى عندما تكون mutated = false.

### 35.5 Enums والـfactory

القيم الملزمة:

    BindingStatusEnum: ACTIVE, INACTIVE, RELEASED
    RegistryRoleEnum: CURRENT_CANONICAL, HISTORICAL_CANONICAL, ACTIVE_ALIAS, RETIRED_ALIAS
    MatchKindEnum: CURRENT, ALIAS, HISTORICAL, RETIRED_ALIAS, NONE
    InputFormCanonicalityEnum: CANONICAL, NON_CANONICAL, INVALID, NOT_APPLICABLE
    AvailabilityStatusEnum: AVAILABLE, OWNED_BY_SAME_BINDING, OWNED_BY_OTHER_BINDING, RESERVED, INVALID
    ScopeTransitionModeEnum: MOVE, PARALLEL
    ClaimIntentModeEnum: EXACT, GENERATED
    ChangeTypeEnum: ASSIGNED, CHANGED, RESTORED, DEACTIVATED, REACTIVATED, RELEASED, RELEASED_ALL, ALIAS_ADDED, ALIAS_RETIRED, ALIAS_REACTIVATED, ALIAS_PROMOTED
    OperationTypeEnum: ASSIGN_EXACT, ASSIGN_GENERATED, CHANGE_EXACT, CHANGE_GENERATED, RESTORE_HISTORICAL, DEACTIVATE, REACTIVATE, RELEASE_CLAIM, RELEASE_ALL, TRANSITION_SCOPE, ATOMIC_TRANSFER, ADD_ALIAS, RETIRE_ALIAS, REACTIVATE_ALIAS, PROMOTE_ALIAS, ADOPT_CURRENT, ADOPT_HISTORICAL, ADOPT_ALIAS
    SortDirectionEnum: ASC, DESC
    AliasSortFieldEnum: ID, SLUG, UPDATED_AT
    RegistrySortFieldEnum: ID, SLUG, ROLE, UPDATED_AT
    BindingSortFieldEnum: ID, ENTITY_TYPE, ENTITY_KEY, UPDATED_AT

SlugEngineFactory هو class نهائي framework-neutral بالـsignature الوحيدة:

    public static function create(
        PDO $pdo,
        SlugProfileRegistryInterface $profiles,
        ReservedSlugPolicyInterface $reservedPolicy,
        ClockInterface $clock,
    ): SlugEngine;

SlugEngine هو aggregate عام نهائي ينفذ SlugTextServiceInterface وSlugProfileRegistryInterface وSlugScopeRegistryInterface وSlugLifecycleServiceInterface وSlugQueryServiceInterface وSlugManagementQueryInterface، ولا يضيف contract آخر.
لا يملك SlugEngine public constructor؛ إنشاؤه الوحيد هو return value من SlugEngineFactory::create، ولا يحق للمستهلك إنشاء PDO أو repositories داخلية من خلاله.

### 35.6 قاعدة result وreplay

الـresult snapshot version 1 يطابق DTO discriminator الآتي: mutation أحادي يطابق SlugMutationResultDTO، transition يطابق ScopeTransitionResultDTO، transfer يطابق AtomicTransferResultDTO، adoption يطابق AdoptionResultDTO. BindingStateResultDTO داخل aggregates ليس اختيارًا بديلًا؛ هو participant contract لازم يصف before/after/mutated/revision/events. decoder يرفض discriminator أو schema version غير المعروف كـSlugPersistenceInvariantException، ولا يبني DTO من current state.

### 35.7 فهرس أسماء Commands

الفهرس المختصر للأنواع المحددة أعلاه هو:

```text
AssignExactCommand
AssignGeneratedCommand
ChangeExactCommand
ChangeGeneratedCommand
RestoreHistoricalCommand
DeactivateBindingCommand
ReactivateBindingCommand
ReleaseClaimCommand
ReleaseAllOwnershipCommand
TransitionScopeCommand
AtomicTransferCommand
AddAliasCommand
RetireAliasCommand
ReactivateAliasCommand
PromoteAliasToCurrentCommand
AdoptCurrentCommand
AdoptHistoricalCommand
AdoptAliasCommand
PurgeBindingCommand
```

هذا الفهرس ليس عقدًا بديلًا؛ signatures والحقول والتحقق الملزمة هي في §§35.1–35.6. لا تقبل Commands حقل `display_order` أو persistence internals.

### 35.8 فهرس أسماء Criteria

```text
BindingCriteria
CurrentSlugCriteria
ResolutionCriteria
AliasCriteria
HistoryCriteria
RegistryCriteria
ScopeCriteria
BindingSearchCriteria
RegistrySearchCriteria
```

هذه أسماء فقط؛ تفاصيل constructors والتحقق في §35.3. لا تستخدم Criteria كـresult DTO ولا DTO كـexecution intent.

### 35.9 فهرس أسماء DTOs

```text
GeneratedSlugDTO
CanonicalSlugDTO
LookupCanonicalizationDTO
ScopeProfileRequestDTO
BindingIdentityDTO
ScopeTransitionClaimIntentDTO
TransferReplacementIntentDTO
SlugAvailabilityDTO
SlugResolutionDTO
SlugMutationResultDTO
BindingStateResultDTO
ScopeTransitionResultDTO
AtomicTransferResultDTO
AdoptionResultDTO
ScopeDTO
BindingDTO
BindingStateDTO
RegistryClaimDTO
CurrentSlugDTO
AliasDTO
HistoryEventDTO
BindingPageDTO
RegistryPageDTO
HistoryPageDTO
AliasCollectionDTO
AuditContextDTO
```

هذه أسماء فقط؛ الحقول والـnullability وresult snapshot contract في §35.4 و§35.6. JSON representation لا تعرض Host records أو internal PDO.

### 35.10 فهرس أسماء Enums

```text
BindingStatusEnum
RegistryRoleEnum
MatchKindEnum
InputFormCanonicalityEnum
AvailabilityStatusEnum
ScopeTransitionModeEnum
ChangeTypeEnum
HistoryEventTypeEnum
ClaimIntentModeEnum
OperationTypeEnum
SortDirectionEnum
AliasSortFieldEnum
RegistrySortFieldEnum
BindingSortFieldEnum
```

`SlugProfileKey` و`SlugScope` و`EntityReference` value objects وليست enums؛ القيم الملزمة للـEnums موثقة في §35.5.

## 36. Exception taxonomy

### 36.1 العقد الفعلي ضد maatify/exceptions

الـmarker العام هو:

    interface SlugExceptionInterface extends \\Throwable {}
    interface SlugDomainExceptionInterface extends SlugExceptionInterface {}

هذه هي package bases والآباء المنشورون الفعليون في maatify/exceptions ^1.0:

| package base | exact published parent | default semantic |
|---|---|---|
| SlugValidationException | Maatify\\Exceptions\\Exception\\Validation\\InvalidArgumentMaatifyException | validation، HTTP 400، INVALID_ARGUMENT |
| SlugBusinessRuleException | Maatify\\Exceptions\\Exception\\BusinessRule\\BusinessRuleMaatifyException | business rule، HTTP 422، BUSINESS_RULE_VIOLATION |
| SlugConflictException | Maatify\\Exceptions\\Exception\\Conflict\\GenericConflictMaatifyException | conflict، HTTP 409، CONFLICT |
| SlugNotFoundBaseException | Maatify\\Exceptions\\Exception\\NotFound\\ResourceNotFoundMaatifyException | not found، HTTP 404، RESOURCE_NOT_FOUND |
| SlugUnsupportedException | Maatify\\Exceptions\\Exception\\Unsupported\\UnsupportedOperationMaatifyException | unsupported، HTTP 409، UNSUPPORTED_OPERATION |
| SlugSystemException | Maatify\\Exceptions\\Exception\\System\\SystemMaatifyException | system، HTTP 500، MAATIFY_ERROR؛ ينفذ defaultErrorCode(): ErrorCodeInterface ويرجع ErrorCodeEnum::MAATIFY_ERROR |

كل base أعلاه abstract package class، يطبق SlugDomainExceptionInterface، ولا يغير constructor الموروث من MaatifyException. هذا constructor هو message، code، previous، errorCodeOverride، httpStatusOverride، isSafeOverride، isRetryableOverride، meta، policy، escalationPolicy كما نشرته dependency؛ لذلك previous محفوظ عند أي wrapping مقصود.

الـconcrete hierarchy الملزمة:

    SlugInvalidArgumentException          extends SlugValidationException
    SlugCannotBeGeneratedException        extends SlugBusinessRuleException
    SlugNotFoundException                 extends SlugNotFoundBaseException
    SlugProfileConfigurationException     extends SlugValidationException
    SlugProfileNotFoundException          extends SlugNotFoundException
    SlugScopeProfileMismatchException     extends SlugConflictException
    SlugScopeRetiredException             extends SlugBusinessRuleException
    SlugAlreadyClaimedException           extends SlugConflictException
    SlugReservedException                 extends SlugConflictException
    SlugRevisionConflictException         extends SlugConflictException
    SlugAllocationExhaustedException      extends SlugConflictException
    SlugIdempotencyConflictException      extends SlugConflictException
    SlugCurrentClaimReleaseException      extends SlugBusinessRuleException
    SlugPurgeNotPermittedException        extends SlugBusinessRuleException
    SlugTransactionParticipationException extends SlugUnsupportedException
    SlugUnsupportedDriverException        extends SlugUnsupportedException
    SlugPersistenceInvariantException     extends SlugSystemException

الآباء أعلاه أسماء PHP فعلية ومحددة لكل family، والـpublished family bases هي MaatifyException وValidationMaatifyException وBusinessRuleMaatifyException وConflictMaatifyException وNotFoundMaatifyException وUnsupportedMaatifyException وSystemMaatifyException، والـpublished leaf classes المحددة في الجدول هي التي تستخدمها الحزمة فعليًا. لا تحوّل الحزمة كل SQLSTATE 23xxx؛ Duplicate MySQL 1062 مع constraint context فقط يتحول إلى conflict، وغير ذلك يعاد كـThrowable infrastructure مع previous.

### 36.2 فهرس التجميع الدلالي

الفهرس الدلالي المختصر (والـinheritance الملزم في §36.1):

```text
SlugDomainExceptionInterface
├── SlugValidationException
│   ├── SlugInvalidArgumentException
│   └── SlugProfileConfigurationException
├── SlugBusinessRuleException
│   ├── SlugCannotBeGeneratedException
│   ├── SlugScopeRetiredException
│   ├── SlugCurrentClaimReleaseException
│   └── SlugPurgeNotPermittedException
├── SlugConflictException
│   ├── SlugAlreadyClaimedException
│   ├── SlugReservedException
│   ├── SlugRevisionConflictException
│   ├── SlugAllocationExhaustedException
│   ├── SlugIdempotencyConflictException
│   └── SlugScopeProfileMismatchException
├── SlugNotFoundBaseException
│   ├── SlugNotFoundException
│   └── SlugProfileNotFoundException
├── SlugUnsupportedException
│   ├── SlugTransactionParticipationException
│   └── SlugUnsupportedDriverException
└── SlugSystemException
    └── SlugPersistenceInvariantException
```

هذا الفهرس لا يعرّف inheritance مستقلًا. الآباء الفعليون وقواعد تحويل الخطأ هي في §36.1؛ لا تستخدم الحزمة broad catch لتحويل كل SQLSTATE 23xxx. Duplicate MySQL 1062 مع constraint context فقط يتحول إلى conflict، وغير ذلك يعاد كـThrowable infrastructure مع previous. transaction catch يعيد original throwable بعد rollback.

## 37. Consumer workflow

المسار المدعوم الذي يجب أن تثبته الأمثلة والـHarness هو:

```text
Host input
→ public Command/Query
→ SlugLifecycleServiceInterface أو SlugQueryServiceInterface
→ Profile + Reservation + PDO MySQL boundary
→ SlugMutationResultDTO أو SlugResolutionDTO أو semantic exception
```

stateless path يحقن profile registry فقط وينتج `GeneratedSlugDTO`. persisted path يحقن PDO/Clock/policy ويثبت assign ثم change ثم resolve القديم كـhistorical والجديد كـcurrent. Host هو الذي يقرر أي SEO redirect أو HTTP response ينشئ بعد قراءة النتيجة.

## 38. Required canonical test vectors

على الأقل يجب تثبيت vectors الآتية في tests والـHarness:

| Profile | Input | Expected |
|---|---|---|
| unicode-v1 source | `آيفون ١٧ برو` | `آيفون-١٧-برو` |
| unicode-v1 source | `iPhone-١٧` | `iphone-١٧` |
| ascii-v1 source | `Über Café` | `uber-cafe` |
| both source | `❤️🔥` | `SlugCannotBeGeneratedException` |
| unicode-v1 claim | `Hello World` | reject |
| unicode-v1 lookup | `iPhone-Pro` | canonical `iphone-pro`, non-canonical input |
| unicode-v1 lookup | `hello!!!` | reject/`NONE + INVALID`, never `hello` |
| both | NFC/NFD equivalent letters | same canonical identity |
| both | invalid UTF-8/NUL/Cf/control/path separator | reject before mutation |
| both | 160 code points + `-2` | safe bounded candidate ≤160 |
| both | 161-code-point exact claim | reject without truncation |
```

تضاف vectors لـreserved words، same-binding restore، aliases، scopes، transfers، adoption، transaction، and concurrency كما في خطة التنفيذ.

## 39. Seven risk gates

### Gate R1 — bootstrap/current pointer

يثبت integration أن placeholder ثم Registry ثم pointer update يعمل بلا circular FK، وأن كل invariant violation يفشل مغلقًا ولا يعيد fallback.

### Gate R2 — transfer وmulti-binding locks

يثبت system/concurrency أن current transfer مع replacement وnon-current transfer وtransfer مقابل claim/mutation منافس كلها atomic وبـlock order واحد، ولا تظهر unowned gap أو lost update.

### Gate R3 — Registry roles/integrity

يثبت real MySQL schema role CHECKs وunique scope-slug/current-marker/binding-slug وpackage-local FKs، وأن كل role transition ينتج صفًا واحدًا صحيحًا.

### Gate R4 — canonical empty dimensions

يثبت أن `null` وغياب locale/context يساويان `''` المخزن، وأن string empty صراحةً مرفوضة، ولا توجد Scope duplicates بسبب NULL.

### Gate R5 — profile/Unicode contracts

يثبت vectors والفروق الثلاثة بين generation/claim/lookup، exact profile names، ICU/extension requirements، invalid/security handling، truncation، collision suffix.

### Gate R6 — driver/transaction/savepoint

يثبت RC1 MySQL 8.0.36 فقط، direct PDO، duplicate 1062 mapping، package-owned transaction، caller savepoint، outer rollback، وfail-before-mutation.

### Gate R7 — history/live ownership/replay contract

يثبت snapshots والsequence وtransfer out/in وrelease/reuse/purge، وoperations evidence وfingerprint وresult snapshot replay بعد تغير live state، وأن management يفصل live Registry عن retained History وأن audit لا يدعي ownership حية.

## 40. Acceptance contract لـRC1

يكون implementation candidate جاهزًا للمراجعة فقط بعد:

1. public contracts وCommands/Criteria/DTOs/Enums/Exceptions مطابقة لهذا المستند؛
2. profiles vectors والسياسات الأمنية deterministic على runtime المعلن؛
3. MySQL schema وindexes وconstraints مثبتة على MySQL 8.0.36؛
4. unit وintegration وsystem وreal concurrency وtransaction evidence مكتملة؛
5. Consumer Verification Harness خارجي يثبت Composer production autoload ويعمل مرتين من clean states؛
6. PHPStan level max بلا baseline/ignoreErrors، Composer validation/platform/audit، والـfull applicable CI gate؛
7. لا Host FKs/JOINs أو SEO/framework coupling؛
8. `git diff --check` وchanged-file scope وdirect review مكتملة وفق Plan.

هذا عقد قبول لاحق، وليس claim أن أي بند اجتاز الآن.

## 41. ما يبقى خارج RC1

يبقى خارج RC1 دعم MariaDB/PostgreSQL/SQLite، profile migration/rewrite لScope populated، تغيير profile existing scope، generic ETL أو batch/dry-run adoption، distributed lock خارج MySQL transactions، route/URL/HTTP/SEO/framework adapters، automatic title monitoring، Host entity deletion/existence، Admin UI/auth، event dispatcher requirement، وStable release/tag/Packagist publication. يمكن بناء هذه كقرارات أو adapters لاحقة دون تغيير core identity إذا اعتمدت منفصلًا.

## 42. قواعد الاتساق والتنفيذ

- كل claim عن runtime في المستند هو target contract لا وصف implementation موجود.
- package-owned transaction لا تتداخل مع caller-owned transaction إلا savepoint contract.
- profileKey لا يدخل Scope uniqueness أو Binding uniqueness.
- source scope لا يتغير في transition، وtransfer لا يعبر Scope.
- release وحده يحرر ownership؛ deactivate/retire/change لا يحررها.
- History ليست live Registry، وreleased claim لا تحل binding القديم.
- Availability advisory، وdatabase unique authoritative.
- pagination mechanics shared، وdomain filters package-owned.
- كل direct dependency أو extension معلنة؛ لا ambient behavior.

## 43. معايير عدم الادعاء

لا يستخدم تقرير التنفيذ عبارات `implemented`, `passed`, `portable`, `accepted`, أو `release-ready` إلا مع evidence مطابق للبوابة المقصودة. هذا الـBlueprint يثبت الاختيارات فقط؛ لا يثبت independent review أو final acceptance أو production deployment.

## 44. Traceability إلى Discussion Draft

كل invariant من Discussion §49 منقول حرفيًا في §49 أدناه أو مجسد في الأقسام المشار إليها في جدول §50. كل قرار من Discussion §50 له قرار منفذ ومكان implementation/evidence محدد؛ لا توجد أسئلة architectural مفتوحة لازمة لبدء التنفيذ.

## 45. قرارات تنفيذية مختصرة

| المجال | القرار RC1 |
|---|---|
| DB | PDO MySQL / MySQL 8.0.36 فقط |
| Profiles | `unicode-v1`, `ascii-v1` |
| Slug max | 160 Unicode code points |
| Auto allocation | base ثم `-2` إلى `-1000` |
| Scope empty | API null → DB empty string، columns NOT NULL |
| Equality | `utf8mb4_bin` للـtext، `ascii_bin` للمفاتيح |
| Binding states | ACTIVE / INACTIVE / RELEASED |
| Registry roles | CURRENT_CANONICAL / HISTORICAL_CANONICAL / ACTIVE_ALIAS / RETIRED_ALIAS |
| Time | injected `ClockInterface`، UTC `DATETIME(6)` |
| Replay | operations evidence مع fingerprint وversioned result snapshot؛ optional idempotency key؛ correlation audit-only |
| Transfer | same Scope atomic، current يحتاج replacement |
| Transition | target Binding جديد؛ MOVE أو PARALLEL |

## 46. Implementation Plan reference

ترتيب Work Units والـdependency graph والـowned paths والـevidence والـPhase Integration Gate موثق في `docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md`. ذلك المستند لا يغير أي قرار هنا؛ يحدد طريقة إنتاجه والتحقق منه.

## 47. Standards وPhase model

RC1 Preparation تبقى حدًا مستقلًا قبل التنفيذ:

```text
main
└── phase-draft/rc-1
    └── work/rc-1-preparation
```

تنتهي Preparation بعد قبول Blueprint وImplementation Plan، ونقل القرارات الدائمة إلى مواضعها، وحذف Discussion Draft في خطوة الإغلاق المناسبة، ثم دمج PR #2 إلى `phase-draft/rc-1`. بعد نجاح ذلك الإغلاق والتحقق من HEAD الجديد فقط تُنشأ Work Branch/Execution Batch للتنفيذ من `phase-draft/rc-1` المحدث، وتستهدف Implementation PR تلك Phase Draft نفسها؛ لا تستهدف `work/rc-1-preparation` ولا تستخدمها كـimplementation base. `RC1 Implementation` هو Roadmap Phase و`Phase != Branch != PR`، و`main` merge owner-only.

## 48. إغلاق معماري قبل التنفيذ

لا يبدأ Runtime implementation قبل مراجعة هذا المستند مقابل Discussion Draft والمعايير المثبتة، ولا يعاد فتح invariants المقفولة هنا. أي تغيير في profile identity أو DB driver أو public contract أو ownership model يحتاج owner-approved architectural change منفصلًا، وليس تفسيرًا داخل Work Unit.

## 49. Architectural Invariants المحفوظة

1. الحزمة authoritative Slug Lifecycle Engine وليست generator فقط.
2. stateless generation/canonicalization يعمل دون persistence configuration.
3. persisted lifecycle مملوك للحزمة عندما يختاره Host.
4. Slug decoded canonical path-segment token وليس full path/URL أو percent-encoded transport.
5. source generation وexplicit-claim canonicalization وlookup canonicalization عقود مختلفة.
6. lookup لا يطبق lossy generation-only transforms بصمت إلا بتعريف Profile مثبت؛ RC1 لا يضيفها.
7. Entity identity Host-provided وstable وopaque.
8. لا Host FK أو JOIN.
9. Scope first-class.
10. logical Scope identity هي `namespace + localeKey + contextKey`.
11. `profileKey` immutable configuration وليس uniqueness dimension.
12. Binding scope identity immutable؛ movement cross-scope transition صريح.
13. Entity واحد يمكن أن يملك parallel bindings في Scopes مختلفة.
14. versioned Profiles تعرف generation/canonicalization/lookup observable behavior ثابتًا.
15. canonicalization idempotent على canonical inputs.
16. current/historical/active-alias/retired-alias ownership تشترك في authoritative scope-local Registry.
17. normal lifecycle عدا explicit release/transfer يحتفظ ownership؛ change/retire/deactivate لا يحرره، وrelease/transfer استثناءان صريحان.
18. historical canonical يبقى مملوكًا لنفس Binding في normal lifecycle.
19. retired alias يبقى مملوكًا في normal lifecycle.
20. same Binding يستطيع restore historical canonical.
21. same-binding retained ownership ليست cross-binding collision.
22. cross-binding ownership change لا يحدث إلا release صريح ثم reuse، أو atomic transfer صريح، أو destructive erasure.
23. claim release وwhole-binding release وpurge مفاهيم مختلفة.
24. exact claim وautomatic allocation نيتان مختلفتان.
25. database uniqueness هي claim authority النهائية.
26. availability advisory.
27. same-slug races محمية بdatabase uniqueness.
28. same-binding races محمية revision/locking.
29. multi-row lifecycle/history mutations atomic.
30. caller-owned transactions لا commit أو rollback لها الحزمة.
31. savepoint/nested participation driver-specific ومختبر.
32. supported adapter يفشل قبل mutation بدل إضعاف caller-transaction atomicity المعلنة.
33. mutation replay/idempotency semantics صريحة.
34. retained normal-lifecycle history immutable.
35. aliases first-class ومختلفة عن canonical history.
36. deactivation يغير binding status فقط ولا يحرر/يمسح current ownership.
37. resolution يفصل match kind عن binding status.
38. input-form canonicality مختلفة عن primary canonical target.
39. historical/alias resolution تشير مباشرة إلى current binding state لا redirect chains.
40. released claims ليست live resolution ownership ولو بقي audit history.
41. Unicode first-class.
42. Arabic يعمل دون forced ASCII conversion.
43. ASCII/transliteration profile/strategy منفصل.
44. built-in deterministic Profiles لها vectors وruntime dependencies/extensions مضبوطة.
45. reserved-slug policy مدعومة في RC1.
46. retained ownership لا تبطلها reservation policy لاحقة بصمت.
47. legacy adoption مدعومة ومتوافقة مع target profile resolution contract.
48. package exposes supported management/query contracts للبيانات المملوكة.
49. Host يملك configuration/connection؛ persistence تستقبل explicit dependencies.
50. lifecycle time يستخدم `ClockInterface` ولا تصبح DB defaults clock سلوكية منافسة.
51. shared pagination تستخدم Maatify persistence capability حيث تنطبق.
52. package exceptions تستخدم Maatify exception hierarchy.
53. unknown infrastructure failures لا تُلف بصمت أو عشوائيًا.
54. supported DB drivers معلنة وليست portability مفترضة.
55. direct runtime packages/extensions معلنة صراحةً.
56. Slug لا يعتمد SEO.
57. SEO لا يعتمد Slug.
58. Host/adapter يملك integrations بين Slug والمجالات الأخرى.
59. intentional cross-binding transfer منسق atomic لمنع gaps أو races.

## 50. إغلاق Open Decisions 1–34

| القرار | الإغلاق المحدد | موضع العقد أو الدليل المطلوب |
|---:|---|---|
| 1 | PDO MySQL مع MySQL Server 8.0.36 فقط؛ لا MariaDB/PostgreSQL/SQLite | §11، §40، Plan §9 |
| 2 | Profile names هما `unicode-v1` و`ascii-v1` فقط | §7.1–§7.3، Plan §3 |
| 3 | source rules: Unicode NFC/lowercase/allowed-run→hyphen؛ ASCII ICU transliteration ثم ASCII rules | §7.1–§7.2، §38 |
| 4 | exact claim يطبق NFC/lowercase فقط ثم strict candidate validation؛ لا source lossy transforms | §7، §17، §38 |
| 5 | lookup يطبق NFC/lowercase فقط ثم validation؛ invalid لا يولد candidate | §7، §30، §38 |
| 6 | NFC لكل built-in Profile عبر ext-intl | §7.1–§7.2، §34 |
| 7 | ICU `Any-Latin; Latin-ASCII`، ICU major 74 فقط، ext-intl | §7.2، §11، Plan §3 |
| 8 | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql`، وMaatify dependencies direct | §11، §34 |
| 9 | reject invalid UTF-8/NUL/Cc/Cs/Cf/path separators؛ mixed scripts مسموحة | §8، §38 |
| 10 | max 160 Unicode code points؛ exact يرفض، generated يقص code points ويحجز suffix | §8، §45 |
| 11 | MySQL `utf8mb4_bin` للنص، `ascii_bin` للمفاتيح؛ no implicit folding/normalization | §11–§13 |
| 12 | API null → DB `''` في NOT NULL locale/context؛ empty string input يرفض | §9.3، §13، Gate R4 |
| 13 | namespace ASCII lowercase pattern؛ opaque values exact valid UTF-8 envelope وحدود محددة | §9 |
| 14 | roles الأربع مع `current_marker` generated unique وrole CHECK؛ released rows تُحذف | §12.3، §16 |
| 15 | pointer nullable بلا FK؛ placeholder ثم Registry ثم pointer update والتحقق داخل transaction | §12.2، §14، Gate R1 |
| 16 | Binding status ACTIVE/INACTIVE/RELEASED وانتقالات §15 | §15، §19 |
| 17 | per-binding sequence وfull snapshots، وoperation_id/operation_key لربط transfer out/in | §12.4–§12.5، §21، Gate R7 |
| 18 | releaseClaim non-current فقط، snapshot ثم delete Registry، current release يرفض | §25.1 |
| 19 | releaseAll يكتب events لكل claim وmarker، يمحو Registry ويضع RELEASED | §25.2 |
| 20 | purge فقط لـRELEASED بلا claims، يمحو History ثم Binding ويحتفظ Scope | §25.3 |
| 21 | alias operations role transitions المحددة؛ retired لا يحرر ownership؛ generated retired same-binding restore محدد | §20، §22 |
| 22 | transition ينشئ target Binding؛ MOVE source INACTIVE، PARALLEL source unchanged؛ لا scope rewrite | §23 |
| 23 | `atomicTransfer` same Scope؛ كل roles قابلة؛ current يحتاج replacement؛ reservation على target؛ atomic history/revision/idempotency و`AtomicTransferResultDTO` source/target aggregate | §24، §35.4، Gate R2 |
| 24 | expectedRevision لكل existing mutation، row locks، CAS، deterministic order §27 | §27–§28 |
| 25 | package-owned BEGIN/COMMIT؛ caller-owned savepoint فقط؛ fail before mutation عند فقد nested guarantee | §26، Gate R6 |
| 26 | operations evidence مستقل: operation identity/type، participant lookup، SHA-256 fingerprint، versioned result snapshot، replay بعد تغير live state، retention/purge؛ correlation audit-only، وno key = natural idempotency فقط | §12.5، §29، §35.6 |
| 27 | كل public interface signature، وCommand/Criteria/DTO fields/types/nullability/validation، وScopeProfileRequestDTO، وtransition/transfer result aggregates، وEnums محددة دون design decision أثناء التنفيذ | §5.1.1، §35.1–§35.6، Gate R7 |
| 28 | adoptCurrent/adoptHistorical/adoptAlias exact commands مع preconditions منفصلة لـabsent/RELEASED/ACTIVE/INACTIVE، current requirement، Registry role، status/revision/history، reservation/profile، وUTC timestamp؛ لا generic ETL | §31، §35.2، Gate R7 |
| 29 | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1`، مع PHP/extensions §34 | §34، Plan §2 |
| 30 | schema/index/operations evidence plan §12–§13، real MySQL/concurrency matrix Plan §9–§10 | §12–§13، Plan §8–§10 |
| 31 | Clock-derived UTC `DATETIME(6)` لكل package timestamp؛ imported timestamp explicit | §33، §31 |
| 32 | adoption snapshot `2fc57f9320f8a7f7147fb20abbcfa311fdf40c28` وManifest المحلي الحالي؛ Standards Freeze أثناء train | §3، §47، Plan §11 |
| 33 | first-use يثبت Scope profile تحت unique lock؛ same profile يتشارك، mismatch يفشل قبل mutation، public `SlugScopeRegistryInterface` وinternal bootstrap path | §14، §5، Gate R1/R4 |
| 34 | Preparation تغلق أولًا بدمج PR #2 إلى `phase-draft/rc-1`، ثم Execution Batch/Work Branch من HEAD المحدث، Implementation PR إلى Phase Draft لا Preparation، مع Phase Integration Gate وHarness وreal DB/concurrency evidence وفق Plan | §40، §46–§47، Plan §1–§15 |

## 51. تسليم هذا العقد

الخطوة التالية هي تنفيذ `SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md` بعد مراجعة المساعد القائد والمالك وفق صلاحيات المشروع. هذا المستند لا يفتح PR ولا يقرر Merge أو Tag أو Release أو Publish، ولا يعدل Discussion Draft أو Standards tree.
