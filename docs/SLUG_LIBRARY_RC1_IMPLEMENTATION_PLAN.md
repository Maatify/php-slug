# Maatify Slug — RC1 Implementation Plan

> **الحالة:** خطة تنفيذ مرتبطة بـ`SLUG_LIBRARY_RC1_BLUEPRINT.md`؛ لا تثبت أن أي Work Unit نُفذت.
>
> **المرجع:** `docs/SLUG_LIBRARY_RC1_BLUEPRINT.md`
> **RC1 source baseline المعتمد للتأليف:** `006ca7c62b4defc62c8ef2b16374b6f60d48a8dc`
> **الـPhase Draft:** `phase-draft/rc-1`
> **Preparation Work Branch:** `work/rc-1-preparation`

هذه الخطة تحول الـBlueprint إلى dependency graph وExecution Batch وWork Units قابلة للتسليم والمراجعة. نطاق مهمة remediation الحالية هو تأليف الوثيقتين فقط؛ لا تنشئ هذه الخطة Runtime أو Schema أو Tests أو CI أو Composer files.

## 1. النتيجة المستهدفة

إنتاج RC1 مكتملة العقد التالية، مع إبقاء الحزمة مستقلة وHost-agnostic:

```text
Package Foundation (Composer/autoload/PHPStan/PHPUnit)
→ Profile/Text
→ Scope + EntityReference
→ direct PDO/pdo_mysql persistence
→ Registry ownership/allocation
→ Lifecycle + Alias + History
→ Transition + Atomic Transfer + Adoption
→ Resolution + Availability + Management
→ Real DB/System/Concurrency/Transaction evidence
→ Consumer Verification Harness
```

لا تعتبر RC1 مكتملة بالـunit tests أو PHPStan وحدهما. كل behavior خارجي أو integration boundary له evidence مناسب في §10.

## 2. Baseline والمعايير والسلطات

### 2.1 Baseline

تبدأ Preparation من `phase-draft/rc-1` عبر `work/rc-1-preparation` وتغلق أولًا بعد قبول Blueprint/Plan، وإكمال Package Reference/release-facing closure gate، ونقل القرارات الدائمة، وحذف Discussion Draft في خطوة الإغلاق المناسبة، ثم دمج PR #2 إلى `phase-draft/rc-1`. يبدأ التنفيذ اللاحق فقط من HEAD المحدث المتحقق منه لـ`phase-draft/rc-1`؛ لا يستخدم `work/rc-1-preparation` كـimplementation base ولا يستخدم `main` كبديل ولا يصلح ancestry تلقائيًا.

### 2.2 Standards snapshot

يستخدم RC1 historical authoring snapshot adoption المحلي عند `2fc57f9320f8a7f7147fb20abbcfa311fdf40c28`. بناءً على explicit Owner decision قبل الـfinal integration، تم تحديث Standards Adoption إلى `44c8827095ab4007c355aa21c56b853f3b49d795`، ويظل `STANDARDS_MANIFEST.md` هو authoritative baseline. المعايير السبعة المنطبقة (بالإصدارات الحالية) هي:

| Standard | Version | موضع التطبيق في الخطة |
|---|---:|---|
| `std-package-building` | 1.4.0 | architecture، PDO، exceptions، PHPStan، persistence |
| `std-composer-package` | 2.0.0 | dependency/autoload/scripts/validation |
| `std-ci-workflow` | 1.1.0 | required gates، real DB، fail-closed CI |
| `std-library-presentation` | 1.0.1 | release state؛ لا يتغير في هذه المهمة |
| `std-testing` | 1.1.0 | unit/integration/system/Harness evidence |
| `std-ai-collaboration-workflow` | 6.0.0 | scope، direct review، evidence، Git |
| `std-github-phase-stack-workflow` | 2.2.0 | Phase/Batch/WU boundaries وFull Gate |

تظل هذه snapshot ثابتة أثناء Active Execution Train. لا يحدث standards refresh داخل train إلا بقرار مالك المشروع أو security/correctness blocker مؤثر.

### 2.3 Git boundaries

التقسيم المفاهيمي هو:

```text
main
└── phase-draft/rc-1
    └── work/rc-1-preparation (Preparation؛ تغلق أولًا)
        [قبول Blueprint/Plan، Package Reference/release-facing closure، نقل القرارات، حذف Discussion Draft، دمج PR #2]
    └── work/rc-1-implementation (لاحقًا من HEAD المحدث لـphase-draft/rc-1)
        └── PR إلى phase-draft/rc-1 (لاحقًا عند فتحها)
```

هذه ليست مساواة بين المصطلحات: `Phase != Branch != PR`. الـdefault هو Batch وWork Branch وPR واحدة لأن العقود وschema وlocks وtests مشتركة. Commits logical milestones تكفي لتتبع WUs؛ لا توجد PR لكل WU لمجرد الرقم. لا تفتح Implementation PR إلى `work/rc-1-preparation`.

Branch التنفيذ المقترحة لا تُنشأ في هذه المهمة. إن قرر المالك استخدامها لاحقًا، تبدأ بعد إغلاق Preparation من أحدث HEAD متحقق لـ`phase-draft/rc-1`، لا من `work/rc-1-preparation` ولا من `main`. Merge إلى `main` وTag وRelease وPublish تبقى owner-only.

## 3. Dependency graph وExecution Waves

### 3.1 الرسم

```text
WU-00 Package Foundation (Composer/autoload/PHPStan/PHPUnit)
        │
        └── WU-01 Contracts/Identity/Profile SPI/Exceptions
                 ├── WU-02 Built-in Profiles/Unicode/Security/Length ──┐
                 └── WU-03 MySQL Schema/PDO/Scope Bootstrap/Transactions ──┴── WU-04 Registry Claim/Allocation/Availability
                                                                                         │
                                                                                         └── WU-05 Lifecycle/Alias/History/Release/Transfer
                                                                                                  ├── WU-06 Transition/Adoption/Resolution/Management ──┐
                                                                                                  └── WU-07 System/Concurrency/Transaction test evidence ─┴── WU-08 Consumer Harness/CI Aggregation/Full Integration Gate
```

### 3.2 Waves

- **Wave 0:** `WU-00` ينشئ package foundation قبل أي Runtime WU: `composer.json`، PHP `^8.4`، direct runtime requirements/extensions من Blueprint §34، PSR-4 production autoload، `require-dev` evidence tools، PHPStan level-max configuration، وPHPUnit bootstrap/configuration.
- **Wave 1:** `WU-01` بعد WU-00 ثم `WU-02`؛ public contracts وprofile contracts تصبح قابلة للتحميل والاختبار قبل persistence.
- **Wave 2:** `WU-03` بعد WU-01؛ WU-03 لا يبدأ قبل تثبيت type/exception/identity contracts.
- **Wave 3:** `WU-04` بعد WU-02 وWU-03؛ claim يعتمد profile canonicalization وschema uniqueness.
- **Wave 4:** `WU-05` بعد WU-04؛ lifecycle يعتمد claim primitives وregistry roles/history sequences.
- **Wave 5:** `WU-06` بعد WU-05؛ transition/adoption/resolve/management يعتمد live model المكتمل.
- **Wave 6:** `WU-07` يتحرك مع كل WU في صورة evidence vertical، ويغلق بعد WU-06 بمصفوفة كاملة.
- **Wave 7:** `WU-08` بعد كل runtime وtest behavior؛ يثبت Consumer Verification Harness وCI aggregation وFull Integration Gate.

يوجد توازٍ dependency-level فقط بين WU-02 وWU-03 بعد WU-01، وبين WU-06 وWU-07 بعد WU-05؛ لا يتداخل ownership داخل WU نفسها. جميعها تشترك في public contracts وschema وtransaction semantics. إذا أثبت التنفيذ ownership مستقلة لاحقًا، يجوز للمساعد القائد إعادة توزيع جزء صغير داخل Batch مع الحفاظ على نفس gates؛ لا يغير ذلك Blueprint.

### 3.3 Work Unit WU-00 — Package bootstrap foundation

#### 3.3.1 Owned paths

```text
composer.json
phpstan.neon
phpunit.xml.dist
tests/bootstrap.php
.php-cs-fixer.php
```

هذه هي package foundation المطلوبة قبل أول Runtime WU. لا ينفذ WU-00 domain behavior أو schema أو production tests، ولا ينشئ `composer.lock` لحزمة library القابلة للنشر.

#### 3.3.2 المسؤولية

ينشئ `composer.json` مستقلًا باسم الحزمة مع PHP `^8.4`، direct Runtime requirements/extensions المقفولة في Blueprint §34، PSR-4 production autoload `Maatify\\Slug\\` إلى `src/`، وPSR-4 autoload-dev إلى `tests/`. يثبت `require-dev` المباشر لـ`phpstan/phpstan ^2.1` و`phpunit/phpunit ^11.5` و`friendsofphp/php-cs-fixer ^3.94`، ويضيف scripts قابلة للتشغيل لـPHPUnit وPHPStan.

ينشئ أيضًا `phpstan.neon` على `level: max` ليشمل `src` و`tests` دون baseline أو `ignoreErrors`، و`phpunit.xml.dist` مع bootstrap `tests/bootstrap.php` وconfiguration لا تتطلب وجود Runtime test paths قبل WU-01، و`.php-cs-fixer.php` للـdry-run عند تفعيله. كل foundation file يظل framework-neutral ولا يضيف custom repository أو Host autoload.

#### 3.3.3 Acceptance criteria

- `composer validate --strict` ينجح، وdependency resolution يثبت كل direct runtime/dev requirement من Blueprint وPlan.
- `composer dump-autoload --optimize --strict-psr` ينتج production PSR-4 autoload، ويفحص الـsmoke check خريطة `Maatify\\Slug\\` إلى `src/` في Composer autoload metadata فقط؛ لا يحاول تحميل production class ولا يفترض وجود `src/`.
- `vendor/bin/phpstan diagnose -c phpstan.neon` و`vendor/bin/phpstan --version` يتحققان من الأداة وقراءة configuration فقط؛ لا ينفذ WU-00 `analyse` على `src` أو `tests`.
- `vendor/bin/phpunit --configuration phpunit.xml.dist --list-tests --do-not-cache-result` يتحقق من configuration وbootstrap دون الادعاء بتشغيل Runtime tests؛ لا يشترط WU-00 وجود Runtime test paths.
- لا يبدأ WU-01 أو أي Runtime WU قبل تحقق هذا القبول.

#### 3.3.4 Evidence

سجل `composer validate --strict`، و`composer dump-autoload --optimize --strict-psr` مع تحقق PSR-4 metadata، وPHPStan version/configuration diagnose، وPHPUnit configuration/bootstrap listing. لا يتضمن WU-00 PHPStan Runtime analysis أو Runtime PHPUnit tests، ولا ينشئ `.gitkeep` أو production placeholder class لمجرد تمرير القبول. هذه الأدلة تثبت قابلية تشغيل الأساس فقط؛ لا تستبدل evidence السلوكية أو Consumer Verification Harness النهائية.

## 4. Work Unit WU-01 — Public domain contracts

### 4.1 Owned paths

```text
src/Identity/
src/Scope/Value/
src/Profile/Contracts/
src/Contract/
src/Command/
src/Criteria/
src/DTO/
src/Enum/
src/Exception/
tests/Unit/Identity/
tests/Unit/Contracts/
tests/Unit/Exception/
```

### 4.2 المسؤولية

تنفيذ value objects `Slug`, `SlugProfileKey`, `SlugScope`, `EntityReference`، و`Slug::fromProfile`، والعقود العامة ذات signatures المحددة في Blueprint §5.1.1، وكل Commands/Criteria/DTOs/Enums/aggregated results في §35، وexception marker/taxonomy في §36. WU-01 لا يملك concrete stateless factories؛ `SlugProfileRegistryFactory` و`SlugTextServiceFactory` يملكهما WU-02 بعد توفر built-in Profiles وregistry/text implementations، و`SlugEngineFactory` يملكه WU-06 بعد persisted services. تتحقق Commands من input contract فقط ولا تنفذ orchestration.

### 4.3 Acceptance criteria

- كل interface/enum/DTO/exception يطابق suffix والنطاق العام.
- `SlugScope` identity لا تحتوي profile، و`EntityReference` لا تطبع أو تفسر قيم Host.
- nullable locale/context يميز بين `null` وempty string وفق §9.
- Commands الموجودة لا تقبل internal IDs بدل domain identity إلا حيث نص Blueprint.
- expected revision وidempotency/audit fields لها validation محددة.
- `Slug` لا يملك public constructor ولا raw/trusted bypass؛ public والـinternal creation path الوحيد هو `Slug::fromProfile` بعد `assertCanonicalSlug`.
- `assignExact/assignGenerated` يقبلان absent أو `RELEASED` فقط؛ `ACTIVE/INACTIVE` يرفضان بـ`SlugAssignmentNotPermittedException` قبل أي mutation.
- `expectedRevision = null` يثبت Binding absent فقط؛ كل Binding موجود، بما فيه `RELEASED`، يتطلب revision الحالية صراحةً، وإعادة فتح RELEASED مع null مرفوضة.
- بعد إنشاء source وtests المملوكة لـWU-01 يبدأ أول Runtime PHPUnit run وأول `vendor/bin/phpstan analyse` على المسارات الموجودة؛ لا يسبق ذلك أي gate في WU-00.
- `ScopeProfileRequestDTO` وBinding identity وall result aggregates لها fields/types/nullability محددة، ولا توجد operation أو public type تُترك لقرار أثناء التنفيذ.
- `transitionScope` و`atomicTransfer` يعيدان aggregates المحددة في §35 مع source/target before/after/revision/result.
- كل result aggregate يطبق Result Snapshot JSON v1 في Blueprint §35.6 حرفيًا: `result_type` tokens الأربعة، top-level/result key order، snake_case nested shapes، enum/date/list/null rules، flags، ورفض missing/extra/unknown keys؛ لا توجد صيغة serialization بديلة أو قرار متروك للمنفذ.
- encoder/decoder pure tests تثبت round-trip encode/decode لـ`mutation` و`transition` و`transfer` و`adoption`، وتثبت أن encoder يحفظ النتيجة الأصلية بـ`replayed=false` وأن replay DTO وحده يغيرها إلى `true` دون mutation للـsnapshot.
- exception parent لكل package family محدد باسم exact published class في `maatify/exceptions` كما في §36.
- Exceptions تستند إلى stable `maatify/exceptions` hierarchy ولا تبتلع Throwable.

### 4.4 Evidence

Unit tests لكل validation boundary، JSON snapshots لكل DTO، وResult Snapshot JSON v1 fixtures لكل result type وnested type، مع round-trip encode/decode، ورفض missing/extra/duplicate keys وunknown discriminator/schema version وwrong scalar/enum/date/list types بـ`SlugPersistenceInvariantException`. تثبت الاختبارات أن Criteria ليست DTO وأن Commands لا تنفذ persistence. لا يُقبل WU-01 إذا احتاج WU لاحقة إلى إعادة تسمية public type أو اختيار field/order/encoding.

## 5. Work Unit WU-02 — Built-in profiles

### 5.1 Owned paths

```text
src/Profile/BuiltIn/
src/Profile/Registry/
src/Profile/Runtime/
src/Factory/
src/Generation/
src/Canonicalization/
src/Validation/
src/Text/
tests/Unit/Profile/
tests/Unit/Generation/
tests/Unit/Canonicalization/
tests/Unit/Validation/
tests/Unit/Factory/
```

### 5.2 المسؤولية

تنفيذ `unicode-v1` و`ascii-v1`، Profile registry implementation، وstateless text-service wiring و`SlugProfileRegistryFactory` و`SlugTextServiceFactory`، والفصل بين source/claim/lookup، security policy، ICU/Unicode compatibility tuple، code-point length، suffix preparation، وreserved-policy SPI دون persistence. factories هنا لا تقبل PDO ولا تنشئ connection أو operations evidence.

### 5.3 Acceptance criteria

- لا يتغير output profile مضمن بصمت؛ registry يمنع duplicate أي key، built-in أو custom، بـ`SlugProfileAlreadyRegisteredException` ولا يستبدل registration بصمت.
- `unicode-v1` يستخدم NFC وICU Unicode lowercase وallowed set `L/M/Nd/U+002D`، ويبقي Arabic، ولا يعتمد على mbstring case tables.
- كلا الـProfiles يرفضان ICU major غير 74 أو Unicode data غير 15.1 بـ`SlugRuntimeCompatibilityException` (semantic environment failure لا validation/HTTP400)؛ `ascii-v1` يستخدم ICU ID `Any-Latin; Latin-ASCII`.
- exact claim لا يحول spaces/punctuation، وlookup لا يطبق generation-only transforms.
- invalid UTF-8/NUL/Cc/Cs/Cf/path separators ترفض قبل lossy transform.
- max 160 code points؛ exact الطويل يرفض؛ generated suffix يحجز الطول.
- base ثم `-2` إلى `-1000` فقط؛ exhaustion semantic exception.
- generated reservation للمرشح الجديد تُتجاوز إلى المرشح التالي وتُحسب ضمن 1000 محاولة (base ثم `-2` إلى `-1000`)؛ حجز جميع المرشحات يعطي `SlugAllocationExhaustedException`، بينما exact/adoption يعطيان `SlugReservedException` مباشرةً.
- vectors §38 كلها ناجحة، ومن ضمنها `hello!!!` في lookup invalid لا `hello`.
- runtime compatibility vectors تنجح على كل minor PHP 8.x مسموح به من Composer `^8.4` ومقبول في CI (الحالية 8.4 و8.5، مع إضافة stable minor لاحق قبل release) مع ICU 74 وUnicode data 15.1، وتفشل مغلقًا خارج tuple؛ لا يوجد PHP ceiling.

### 5.4 Evidence

Property tests لـidempotence، data-driven vectors لكل Profile، runtime extension-missing tests التي تفشل مغلقًا، وtests تثبت أن source وclaim وlookup ليست aliases مخفية لبعضها.

## 6. Work Unit WU-03 — MySQL-compatible schema وPDO boundary

### 6.1 Owned paths

```text
schema/mysql/README.md
schema/mysql/001_slug_rc1.sql
src/Infrastructure/Persistence/PDO/Connection/
src/Infrastructure/Persistence/PDO/Scope/
src/Infrastructure/Persistence/PDO/Schema/
src/Internal/Transaction/
src/Internal/ResultSnapshot/
src/Persistence/Contract/
src/Infrastructure/Persistence/PDO/Operations/
src/Scope/Persistence/
tests/Integration/Schema/
tests/Integration/Persistence/Operations/
tests/Integration/Persistence/Scope/
tests/Unit/ResultSnapshot/
tests/Unit/Persistence/Scope/
```

هذه paths مستقبلية ضمن تنفيذ RC1؛ لا تُنشأ في مهمة التأليف الحالية.

### 6.2 المسؤولية

إنشاء Schema واحدة وفق Blueprint §12–§14، direct PDO repositories/row hydration، capability guard لحد `pdo_mysql`، scope first-use/bootstrap، package-owned transaction/savepoint coordinator، وClock persistence adapter.

### 6.3 Acceptance criteria

- schema واحدة تعمل على MySQL-compatible verification target يحقق capabilities Blueprint §11، وتنشئ الجداول بترتيب §12، وتحتوي prefix `maa_slug_` وجميع named unique indexes/FKs package-local. تتحقق package من status/role/event/cross-field invariants؛ لا تتطلب schema generated columns أو CHECK enforcement أو vendor/version branch.
- `current_registry_id` nullable بلا circular FK، وplaceholder sequence §14 قابل للتنفيذ atomic.
- `utf8mb4_bin` و`ascii_bin` موجودتان حيث قررهما Blueprint، ولا تعتمد schema على collation normalization.
- قفل Binding مع `current_registry_id` والتحقق داخل transaction يفرضان current claim واحدة لكل Binding، و`uk_registry_scope_slug` authority نهائية؛ لا يعتمد التنفيذ على generated columns.
- `maa_slug_operations` يحتفظ بـoperation identity وoperation type وfingerprint وversioned result snapshot، و`maa_slug_operation_bindings` يثبت participants وunique replay lookup للـsingle/source/target. participant FK لا يُدرج إلا بعد أن يوجد Binding ID؛ first-create sequencing هو §29.
- `result_snapshot` يُخزن كنص UTF-8 exact-safe بعد validation؛ لا يعتمد WU-03 على native JSON functions أو JSON server behavior.
- keyed operations تنفذ identity/bootstrap ثم participant reservation داخل transaction بحالة `IN_PROGRESS` غير المرئية للقراء، ثم mutation وHistory ثم تنتقل مرة واحدة إلى `COMMITTED` مع snapshot؛ لا يبقى `IN_PROGRESS` ملتزمًا ولا يقبل snapshot تعديلًا بعد commit، وفشل أي خطوة يمحو الصفوف وplaceholder الجزئية.
- Result Snapshot Encoder/Decoder الداخلي يطبق §35.6 حرفيًا داخل حدود WU-03: `result_type` يطابق DTO، `result_schema_version = 1`، exact top-level/nested shape وfield order، flags، UTC `DateTimeImmutable` بستة microseconds، nullable/list/enum rules، ورفض أي missing/extra/duplicate/unknown key أو mismatch بـ`SlugPersistenceInvariantException`.
- عند decode من صف `COMMITTED` يثبت WU-03 مساواة JSON `result_type`/`result_schema_version`/`result.operation_type` مع الأعمدة الثلاثة، وnon-null مساواة `result.operation_key` مع عمود `operation_key`، ومساواة operation key في كل History event وnested result؛ كل mismatch يرمى `SlugPersistenceInvariantException` قبل hydration أو replay.
- كل successful keyed operation يكتب snapshot النتيجة الأصلية الكاملة مرة واحدة بـ`replayed=false` بعد live state وHistory؛ أي UPDATE لاحق لـ`result_snapshot` أو discriminator/version مرفوض invariantيًا. replay يقرأ snapshot الملتزم ويفككه فقط، يعيد نفس DTO مع `replayed=true` في الذاكرة دون تعديل snapshot أو live state، ولا يستعمل Registry/Binding الحالية لإعادة البناء؛ retention وpurge يطبقان §12.5 و§29، مع FK/index names المحددة.
- capability guard يثبت `pdo_mysql` وtransactional/InnoDB semantics وsavepoints وrow locks وunique/FK وexact-string و`DATETIME(6)` capabilities قبل mutation؛ لا يرفض runtime بسبب product/version string، ولا يضيف PostgreSQL أو SQLite fallback.
- PDO config وunique placeholders وint LIMIT/OFFSET وmixed-row annotations مطبقة.
- duplicate conversion محصورة في `pdo_mysql`-compatible `errorInfo[1] === 1062` مع constraint context؛ duplicate `uk_binding_identity` في first-create لمسارات `assignExact`/`assignGenerated`/`adoptCurrent` وtarget `transitionScope` race متوقع لا infrastructure failure: تعتبر `INSERT` statement نفسها failed، ولا يوجد savepoint خاص بالإدراج ولا rollback له، ويستمر التنفيذ داخل package transaction أو savepoint الحالية؛ ثم يعاد قراءة Binding الفائزة تحت `SELECT ... FOR UPDATE`، ويطبق §29 و§26.3: نفس key وfingerprint مع evidence `COMMITTED` يعيد replay snapshot، ونفس key مع fingerprint مختلف `SlugIdempotencyConflictException`، وغياب evidence `SlugRevisionConflictException` كـsemantic/CAS conflict. إذا انتهت classification بفشل semantic أو فشل لاحق، يطبق rollback العام في §26 على مستوى العملية كاملة، وليس rollback خاصًا ببيان `INSERT`. أي duplicate آخر يتبع تصنيفه المحدد في Blueprint §11.1 و§29.
- package-owned transaction rollback وcaller savepoint setup يحدثان قبل mutation.

### 6.4 Evidence

Real MySQL-compatible schema install، capability/constraint violation tests، pointer bootstrap tests، profile first-use race، direct PDO integration، cleanup/repeatability مرتان، وtransaction evidence في §10.

## 7. Work Unit WU-04 — Registry claim/allocation/availability

### 7.1 Owned paths

```text
src/Allocation/
src/Ownership/
src/Availability/
src/Reserved/
src/Internal/Claim/
src/Infrastructure/Persistence/PDO/Registry/
tests/Unit/Allocation/
tests/Integration/Registry/
tests/System/Claim/
```

### 7.2 المسؤولية

تنفيذ internal `claimExact` و`allocateGenerated` خلف lifecycle boundary، availability advisory، reservation evaluation، same-binding ownership classification، unique-race handling، وscope-local Registry role creation.

### 7.3 Acceptance criteria

- exact claim لا ي suffix ولا يبدل Binding آخر.
- generated claim يلتزم candidate order وbounded attempts ويحوّل duplicate الصحيح فقط.
- generated candidate المحجوز لملكية جديدة يُتجاوز إلى candidate التالي ويُحسب ضمن 1000 محاولة (base ثم `-2` إلى `-1000`)؛ exact/adoption المحجوز يفشل بـ`SlugReservedException`، وحجز جميع candidates يعطي `SlugAllocationExhaustedException`.
- same-binding operation×role matrix في §20 هي authority: only the declared natural no-ops are no-ops؛ assign never promotes retained roles، وretired alias لا يعاد canonicalize له ضمن generated path.
- reservation blocks new exact/adoption ownership and target transfer، وgenerated allocation يتجاوز المرشح المحجوز؛ ولا invalidates retained same-binding ownership.
- availability يعيد classifications الخمس ولا ينشئ state ولا يعد بضمان race-free.
- Registry row وcurrent pointer وBinding revision لا تتجزأ عند failure.
- concurrent exact claim يخرج winner واحدًا وsemantic loser؛ concurrent auto allocation ينتج base/suffix deterministic.

### 7.4 Evidence

System workflows من public API لـassign exact/generated، real MySQL-compatible race workers، vectors للأطوال، same slug في Scopes مختلفة، وavailability-vs-claim race.

## 8. Work Unit WU-05 — Lifecycle وaliases وhistory وrelease وtransfer

### 8.1 Owned paths

```text
src/Lifecycle/
src/Alias/
src/History/
src/Transfer/
src/Maintenance/
src/Infrastructure/Persistence/PDO/Binding/
src/Infrastructure/Persistence/PDO/History/
tests/Unit/Lifecycle/
tests/Integration/Lifecycle/
tests/System/Lifecycle/
tests/System/Transfer/
```

### 8.2 المسؤولية

تنفيذ assign/change/restore/deactivate/reactivate، alias role transitions، immutable retained events، claim-level/whole-binding release، purge، atomic same-scope cross-binding transfer، وrevision/lock order §27.

### 8.3 Acceptance criteria

- change يحتفظ بالـprevious canonical كـhistorical؛ لا normal operation تحرر ownership.
- deactivation لا يمسح current؛ inactive resolution يعيد binding status.
- alias retirement لا يحرر slug؛ `addAlias/retireAlias/reactivateAlias/promoteAliasToCurrent` تطبق matrix §20 حرفيًا: retire المتكرر reject لا no-op، وretired لا يُpromote مباشرةً.
- releaseClaim يرفض current؛ releaseAll يكتب per-claim snapshots وmarker ثم يضع RELEASED/pointer NULL.
- purge يعمل فقط لـRELEASED بلا claims، ويحذف History ثم Binding ويترك Scope.
- transfer ينقل الأدوار الأربع إلى Binding target موجودة فقط؛ role target يساوي role source حرفيًا، وcurrent يحتاج source replacement مختلفًا. replacement الجديد يطبق `CHANGED`، والـhistorical لنفس المصدر يطبق `RESTORED`، وactive/retired alias يرفض وفق matrix §20، وexact replacement المساوي للمنقول يرفض قبل mutation، وgenerated replacement يستبعد المنقول؛ لا يوجد تفسير `replacement retained`. target `RELEASED` مسموح للـcurrent فقط، وtarget ACTIVE/INACTIVE ذي current مسموح لغير current.
- transfer يطبق ترتيب lock/demote/replacement/delete/insert/history المحدد في Blueprint §24، ويحفظ `originalSourceRole` قبل أي transient demotion؛ transfer-out/in يسجلان الدور نفسه والهدف يستلمه حرفيًا، ويكتب out/in snapshots وrole snapshots في transaction واحدة وبـ`operation_key` واحدة عند وجود idempotency key، ولا تظهر unowned gap؛ عند غياب key لا توجد operations evidence ويكون operationKey في النتيجة null.
- history sequence يزيد لكل row، وrevision مرة واحدة لكل participant mutated، وresult aggregate يحفظ source/target participant results مع before/after states والـrevisions والـevents بما فيها source replacement؛ لا توجد حقول before/after مباشرة بديلة في `AtomicTransferResultDTO`.
- كل single mutation و`AtomicTransferResultDTO` يبنيان result DTO الأصلي مرة واحدة بترتيب §35.6، ويُشفّران قبل commit بـ`result_type = mutation|transfer` و`result_schema_version = 1`؛ transfer snapshot واحد يضم source/target participant results ولا يعاد تجميعه من live rows عند replay.
- current atomic transfer فقط يملأ `sourceReplacementResult`: nested `operationType = ATOMIC_TRANSFER`، و`operationKey/replayed` يساويان outer، وكلا replay flags يبدآن `false` ويتحولان معًا إلى `true` في الذاكرة. non-current transfer يثبت `sourceReplacementResult = null`؛ nested before/after/revision/historyEvents نهائية وقابلة للملاحظة، و`changeType` وreplacement history يقتصران على `CHANGED` أو `RESTORED` دون transient DB state أو transfer out/in duplication.
- عند وجود idempotency key فقط تُحفظ key/fingerprint والـresult snapshot في operations evidence؛ transfer/transition يستخدمان operation واحدًا ومشاركي SOURCE/TARGET، ولا يكتفيان بإعادة قراءة current state. في first-create، duplicate `uk_binding_identity` يعاد معه lock/read وفحص evidence قبل تصنيف النتيجة؛ نفس key/fingerprint replay، المختلف `SlugIdempotencyConflictException`، وبدون evidence `SlugRevisionConflictException`. بدون key لا تُنشأ operation أو participant rows.
- `assignExact` و`assignGenerated` يقبلان Binding absent أو `RELEASED` فقط؛ `null` للـabsent فقط، وrevision الحالية صراحةً لـ`RELEASED`؛ `ACTIVE/INACTIVE` يرفضان بـ`SlugAssignmentNotPermittedException` قبل أي mutation، ولا إعادة فتح مع `null`.
- History `claim_role_snapshot` و`previous_claim_role_snapshot` وnullable/event applicability validation مطابقة §12.4 و`HistoryEventDTO`؛ release/transfer events لا تعتمد على Registry لاحقة.

### 8.4 Evidence

System tests لكل lifecycle path، history snapshot assertions بعد release/reuse/transfer/purge، concurrent source/target mutation وclaim، deadlock-order test، وrollback assertions لكل multi-row mutation.

## 9. Work Unit WU-06 — Transition وadoption وresolution وmanagement

### 9.1 Owned paths

```text
src/Transition/
src/Adoption/
src/Resolution/
src/Query/
src/Management/
src/Engine/
src/Infrastructure/Persistence/PDO/Query/
tests/Unit/Resolution/
tests/Unit/Engine/
tests/Integration/Adoption/
tests/Integration/Management/
tests/System/Resolution/
tests/System/Transition/
```

### 9.2 المسؤولية

تنفيذ `transitionScope` بوضعَي MOVE/PARALLEL، adoption commands، direct resolution DTO، availability public query، management Criteria/page adapters، وdelegation إلى shared `maatify/persistence` pagination. بعد اكتمال persisted services وpublic query capabilities، يملك WU-06 تنفيذ `SlugEngine` و`SlugEngineFactory` wiring النهائي؛ لا يملك stateless factories التي ينفذها WU-02.

### 9.3 Acceptance criteria

- transition ينشئ target Binding ولا يغير source `scope_id` أو source Registry snapshots.
- MOVE يجعل المصدر INACTIVE، وPARALLEL لا يغير source status؛ كلاهما atomic.
- transition result هو `ScopeTransitionResultDTO` وفيه participant results وsource/target before-after/revisions والـHistory؛ transfer result هو `AtomicTransferResultDTO` بنفس الصراحة، وتكون before/after في transfer داخل `sourceResult` و`targetResult` فقط.
- target profile يطبق على target claim، وprofile mismatch يفشل قبل mutation.
- target first-create duplicate على `uk_binding_identity` يعاد معه target Binding تحت lock وفحص operation participant evidence قبل التصنيف: نفس key/fingerprint replay، fingerprint مختلف `SlugIdempotencyConflictException`، وغياب evidence `SlugRevisionConflictException`؛ لا يُعاد تشغيل mutation تلقائيًا.
- `ScopeTransitionResultDTO` و`AdoptionResultDTO` يطبقان `result_type = transition|adoption` وshapes §35.6 كاملة؛ transition يستخدم aggregate واحدًا source/target، adoption يحفظ `adopted_claim` و`history_event` الأصليين، وكلاهما يعاد من snapshot فقط عند replay دون current-state reconstruction.
- adoptCurrent/adoptHistorical/adoptAlias لها preconditions منفصلة للـabsent/RELEASED/ACTIVE/INACTIVE وrole/status/revision/history في Blueprint §31، وتمر بنفس canonical/profile/ownership/reservation rules؛ `originalOccurredAt` يقبل timezone-aware `DateTimeImmutable` بأي timezone، يتحول إلى UTC، يحفظ 6 microseconds دون rounding، ويرفض فقط خارج مدى `DATETIME(6)` المطلوب دون future/past comparison.
- `adoptCurrent` يقبل `null` revision فقط عند غياب Binding؛ إعادة فتح Binding `RELEASED` تتطلب revision الحالية، و`adoptHistorical` و`adoptAlias` يتطلبان revision صريحة لBinding موجود.
- resolve يفصل `matchKind`, `bindingStatus`, `inputFormCanonicality` ويشير إلى current مباشرة.
- released claim لا تحل، وretained history لا تظهر كlive ownership.
- Criteria تستخدم dependency `PageRequest` باعتباره المدخل الوحيد للـpagination وsort، وpublic results تستخدم `PageResult<T>` وshared `SortDirectionEnum`؛ لا يوجد `$sort` أو direction أو page/per-page parameter منفصل، وdomain sort keys/filters فقط مملوكة لـSlug.
- `PdoPaginator` ينفذ normalization وsort resolution وdirection/defaults وper-page bounds من `PaginationConfig`/`SortWhitelist` المحددة query-by-query في Blueprint §32، وبالقيم الثابتة لكل query: `defaultPerPage = 25`, `minPerPage = 1`, و`maxPerPage = 100`؛ لا تنسب الخطة validation إلى `PageRequest`. count/data predicates وselected columns وrow DTO semantics تبقى Slug-owned، مع tie-breaker `id ASC`، ولا يوجد local pagination DTO/enum أو paginator أو `*PageDTO`.
- acceptance يتضمن exact public signatures §5.1.1 و§35.1–§35.5، ولا يسمح بقرار أثناء التنفيذ حول PageRequest/PageResult أو Engine construction.

### 9.4 Evidence

System resolution matrix، transition source/target races، adoption compatibility/rejection، real management searches، pagination count/order tests لكل `PaginationConfig` query في Blueprint §32، وassertion أن queries لا JOIN Host.

## 10. Work Unit WU-07 — Required behavioral evidence

### 10.1 Owned paths

```text
tests/Fixtures/
tests/Support/
tests/Integration/Concurrency/
tests/Integration/Transactions/
tests/System/Concurrency/
tests/System/Transactions/
```

تظل كل test في WU المالكة للسلوك عند الإمكان؛ WU-07 يملك cross-cutting orchestration وfixtures وreal-race runners فقط، ولا يفصل Runtime/tests إلى PRs مصطنعة.

### 10.2 Unit evidence

- Identity/Scope/ProfileKey/EntityReference validation.
- Profile vectors، idempotence، invalid/security input، length/suffix.
- Commands/Criteria validation وexception taxonomy.
- `Slug` private construction عبر Profile validation، stateless factory path، وحدود `actorKey/reason/correlationKey/idempotencyKey` المطابقة لـschema، ومصفوفة same-binding operation×role في Blueprint §20.
- Pure reservation and availability classification.
- DTO serialization وEnum mappings، بما فيها `HistoryEventDTO` role snapshots وdependency `PageRequest/PageResult/SortDirectionEnum` دون local pagination DTO/enum.
- Result Snapshot JSON v1: round-trip encode/decode لكل `mutation` و`transition` و`transfer` و`adoption`، مع exact `result_type`/DTO mapping، field order، nested `Slug`/`SlugScope`/`EntityReference`/DTO encodings، enum/date/null/list validation، والـflags المحددة؛ malformed JSON، missing/extra/duplicate keys، unknown discriminator/schema version، wrong type، invalid timestamp، وout-of-order list كلها ترفض بـ`SlugPersistenceInvariantException`.
- cross-field fixtures لـ`AtomicTransferResultDTO`: top-level `result_type=mutation` يرفض `ATOMIC_TRANSFER`، بينما `transfer.result.source_replacement_result` يسمح به فقط في current transfer؛ يثبت `operationKey` equality، outer/nested `replayed` equality (`false/false` ثم `true/true`)، nullability في non-current، وfinal replacement-only before/after/revision/history semantics.

### 10.3 Integration evidence

مع اتصال PDO حقيقي إلى MySQL-compatible verification target يحقق capabilities Blueprint §11:

- schema creation/unique indexes/foreign keys/collations والـcapability evidence، بما فيها `maa_slug_operations` و`maa_slug_operation_bindings` وresult snapshots؛
- insert/read-back لـResult Snapshot JSON v1 يثبت أن كل result type round-trips عبر exact-safe text storage دون فقد field أو null أو microseconds، وأن snapshot الأصلي يبقى ثابتًا بعد replay؛
- scope first use/profile mismatch/current-pointer bootstrap؛
- Registry roles and uniqueness؛
- Clock UTC `DATETIME(6)` وHistory snapshots/sequences/role applicability وoperation evidence/replay retention؛
- package-owned transaction and caller savepoint participation؛
- management count/data/pagination؛
- cleanup/repeatability and no host table access.

### 10.4 System evidence

كل workflow يبدأ من public command/query ويصل إلى DTO/result أو semantic exception:

```text
assignExact → changeGenerated → resolve(old historical/new current)
stateless generation/canonicalization عبر factories → DTOs بلا PDO
assignGenerated collision/reservation skip → suffix
add/retire/reactivate/promote alias
deactivate/reactivate
releaseClaim → reuse by another Binding
releaseAll → assign again → purge
transition MOVE/PARALLEL
atomic transfer current/non-current
adopt current/historical/alias
```

### 10.5 Concurrency evidence

تشغل worker processes مستقلة باتصالات PDO منفصلة مع barrier خارج قاعدة البيانات، وتتحقق من:

- two exact claimers for one `(scope,slug)`؛
- two generated allocators؛
- two same-binding mutations with same revision؛
- assign/adoptCurrent على Binding absent مقابل Binding `RELEASED` يثبتان أن `null` لا يعيد فتح الصف الموجود وأن revision الحالية هي شرط CAS؛
- adoption timestamps تقبل أي timezone-aware `DateTimeImmutable`، تتحول إلى UTC، تحفظ microseconds الست، وترفض فقط خارج مدى `DATETIME(6)` المطلوب؛ لا توجد قاعدة future/past بالنسبة إلى Clock.
- same idempotency key with same/different fingerprint، بعد تغير live state، يعيد snapshot أو conflict دون mutation جديدة؛
- كل result type (`mutation`, `transition`, `transfer`, `adoption`) يُعاد replay له بعد تغيير live Registry/Binding/History، ويثبت الاختبار أن DTO المعاد مطابق للأصل عدا `replayed=true` وأن decoder لا يستدعي current-state reconstruction؛
- current atomic transfer يُعاد replay له بعد تغيير live state، ويثبت أن outer وnested `sourceReplacementResult` يعيدان نفس النتيجة الأصلية مع `replayed=true` معًا، دون تعديل snapshot أو أعمدة operation أو إنشاء History/revision جديدة؛
- decode metadata mismatch لكل من JSON/column `result_type` و`result_schema_version` و`operation_type` و`operation_key`، وكل History/nested operation key mismatch، يرمى `SlugPersistenceInvariantException`؛
- malformed/unknown `result_type` أو `result_schema_version`، missing/extra/duplicate key، wrong nested shape/type، timestamp غير UTC أو دون 6 microseconds، وlist غير مرتبة ترفض بـ`SlugPersistenceInvariantException`؛
- mutation بلا idempotency key لا تنشئ operations/participants وتعيد `operationKey = null`، بينما mutation مع key تحفظ وتعيد snapshot immutable.
- multi-binding replay يتحقق من source/target participants وaggregate snapshot الواحد؛
- first-use same profile and conflicting profile؛
- transfer مقابل source mutation؛
- transfer مقابل target mutation؛
- transfer مقابل competing claim؛
- transition مقابل affected binding mutation.

كل test يثبت winner/loser، committed state، revisions، events، وعدم partial rows. لا يعتمد على mocks لإثبات race.

### 10.6 Transaction evidence

يثبت الاختبار:

1. package-owned transaction: exception بعد أول كتابة يمحو كل package rows.
2. caller-owned transaction: package ينشئ savepoint ولا commit/rollback outer transaction.
3. package exception مع Host catch ثم استمرار outer transaction لا يترك partial package state.
4. Host outer rollback يمحو package mutation ضمن نفس transaction.
5. driver/savepoint failure قبل mutation يترك schema state بلا تغيير.

## 11. Work Unit WU-08 — Consumer Verification Harness/CI Aggregation/Full Integration Gate

### 11.1 Owned paths لاحقًا

```text
tools/
.github/workflows/
tests/Consumer/
```

هذه هي ملفات WU-08 النهائية؛ package foundation files أنشأها WU-00 قبل Runtime. لا تُنشأ هذه الملفات في مهمة Blueprint الحالية.

### 11.2 Composer contract

يتحقق WU-08 من foundation التي أنشأها WU-00، ولا يؤجل إنشاء `composer.json` أو autoload أو PHPStan/PHPUnit configuration:

```text
php ^8.4
ext-intl *
ext-mbstring *
ext-pdo *
ext-pdo_mysql *
maatify/exceptions ^1.0
maatify/shared-common ^1.0
maatify/persistence ^1.1
```

ويتحقق أيضًا من `require-dev` المقفولة في Blueprint §34:

```text
phpstan/phpstan ^2.1
phpunit/phpunit ^11.5
friendsofphp/php-cs-fixer ^3.94
```

يستخدم `maatify/persistence` فقط للـpagination stable API، ولا يضاف package-local replacement. لا يضاف `composer.lock` أو `version` أو custom repository في الحزمة القابلة للنشر. `composer validate --strict` و`composer check-platform-reqs` إلزاميان.

### 11.3 PHPStan max

يكون `phpstan.neon` الذي أنشأه WU-00 على `level: max` ويشمل `src` و`tests`. WU-08 يعيد تشغيل الأمر كـgate نهائي:

```bash
vendor/bin/phpstan analyse src tests --level=max
```

لا baseline ولا `ignoreErrors` ولا inline suppression لإخفاء خطأ. أي mismatch في DTO generics أو PDO hydration يصلح في Runtime/type annotations.

### 11.4 CI contract

تطبق WU-08 `CI_WORKFLOW_STANDARD.md` مباشرةً للـCI topology والـaggregate/failure/security/reliability/trigger contract؛ لا تعيد هذه الخطة تعريف القواعد العامة. القيم الخاصة بهذه الحزمة فقط هي:

- Composer constraint هو `php ^8.4` ولا يغلق runtime على PHP 8.4 أو 8.5؛ كل PHP 8.x stable minor يسمح به القيد وتقبله CI Standard يدخل المصفوفة. القيم الحالية هي PHP 8.4 كـminimum وPHP 8.5 كـlatest released compatible minor، ويضاف أي minor لاحق قبل release وفق الـStandard بلا ceiling أو exception.
- lowest dependency resolution يعمل على PHP 8.4، وlatest compatible resolution يعمل على PHP 8.5 حاليًا، ومع كل minor لاحق داخل القيد عند إضافته؛ foundation autoload gate يستخدم بالضبط `composer dump-autoload --optimize --strict-psr`.
- runtime extensions/dependencies هي القيم في Blueprint §34، وPHPUnit configuration هو `phpunit.xml.dist`، وPHPStan configuration هو `phpstan.neon` على `level: max`، وstyle configuration هو `.php-cs-fixer.php`.
- Persistence service هو direct PDO عبر `pdo_mysql` إلى MySQL-compatible target يحقق capabilities §11؛ schema هو `schema/mysql/001_slug_rc1.sql`، وConsumer Verification Harness هو `tests/Consumer/` مع clean repeatability مرتين. أي server version تُختار للـCI هي verification target ثابتة وليست minimum/maximum أو Runtime gate.

تدخل هذه القيم في jobs الخاصة بالحزمة حيث يطلب الـStandard، وتبقى بقية القواعد والأوامر العامة محكومة مباشرةً بالـCI Standard دون نسخة محلية متعارضة.

## 12. Consumer Verification Harness

### 12.1 حدود المستهلك

Harness له Composer root مستقل عن package root، ولا يستخدم package `autoload-dev` أو test bootstrap أو Host autoload أو direct `require/include` لـ`src`.

### 12.2 مساران مطلوبان

- **Local pre-publication:** ينشئ consumer project نظيفًا ويثبت checkout كـComposer dependency عبر repository مؤقت خاص بالHarness فقط، ثم يثبت dependencies، production PSR-4، وpublic workflow. لا يضاف هذا repository إلى `composer.json` للحزمة.
- **Published RC:** بعد نشر RC فعليًا وبموافقة مستقلة، يثبت exact tag `v1.0.0-rc.1` من distribution source المعتمد. هذا الدليل ليس متاحًا في مهمة Blueprint الحالية ولا يُدّعى الآن.

### 12.3 Workflow والـrepeatability

كل run نظيف يحوي:

```text
Composer install/resolve
→ production autoload
→ construct stateless text service with SlugProfileRegistryFactory/SlugTextServiceFactory
→ generate/canonicalize workflow
→ construct public services with injected PDO/Clock/Policy
→ assign/change/resolve workflow
→ observable DTO/result
→ cleanup package DB state
```

ينفذ Harness مرتين من consumer state وdatabase state نظيفين، ويثبت عدم الاعتماد على vendor/generated state سابق. إذا كانت persistence مطلوبة، يستخدم MySQL-compatible server حقيقيًا يحقق capabilities §11، لا SQLite/mock؛ نسخة الاختبار المختارة دليل reproducibility فقط وليست support floor.

## 13. Acceptance criteria على مستوى Execution Batch

لا تغلق Batch إلا إذا تحققت جميع الشروط الآتية:

- كل WU من WU-00 إلى WU-08 مكتملة وفق acceptance الخاصة بها أو لها تصنيف evidence دقيق.
- package foundation من WU-00 موجود وقابل للتشغيل قبل أول Runtime WU، ثم يُعاد التحقق منه في WU-08.
- كل public contract في Blueprint موجود بلا تغيير غير معتمد.
- risk gates R1 إلى R7 في Blueprint §39 لها evidence مستقل.
- unit/integration/system/concurrency/transaction tests ناجحة؛ لا يعتبر unrun أو blocked نجاحًا.
- Consumer Verification Harness نجح مرتين من clean states، ومعه production autoload proof.
- DB evidence يثبت target/version ثابتًا يحقق capabilities §11، ويُذكر بوصفه verification target فقط؛ لا توجد minimum/maximum version claim ولا ادعاء دعم كل MySQL/MariaDB environments.
- Full Applicable CI Standard gate، مع package-specific values في §11.4، ناجح؛ لا تنشئ الخطة gate موازيًا أو أضيق من الـStandard.
- `git diff --check` وstaged diff checks نظيفة، وchanged files داخل Work Branch scope.
- direct review راجعت accumulated diff مقابل أحدث base، وبعد أي remediation تجرى Fresh Full Acceptance Review.

## 14. Phase Integration Gate

عند إغلاق RC1 Full Lifecycle Batch تطبق Full Applicable Verification Set المحكومة مباشرةً بالـCI Standard، لا subset انتقائيًا. القيم الحالية الخاصة بالمصفوفة هي PHP `8.4` و`8.5` وفق §11.4، مع إدخال كل stable minor لاحق داخل `^8.4` قبل release، وMySQL-compatible verification target(s) ثابتة تحقق capabilities §11، schema/runner/configuration paths المحددة هناك، وجميع WU behavioral/concurrency/transaction evidence وConsumer Harness. لا تضيف هذه الخطة قواعد aggregate أو security أو execution reliability محلية؛ consistency review المعمارية وBlueprint/Plan review جزء من إغلاق Phase، لا بديل عن الـCI gate.

تطبق دلالة الـaggregate gate وحالات unexpected skipped أو cancelled أو failure وفق `CI_WORKFLOW_STANDARD.md`؛ documentation-only current task لا تشغل هذه المصفوفة لأنها لا تنتج Runtime، لكن Implementation Batch لا تتجاوزها.

## 15. Commit/Push/PR procedure للتنفيذ اللاحق

هذه الخطة لا تنفذ الإجراء الآن. عند التصريح بتنفيذ RC1:

1. يتحقق المنفذ من source branch وexact HEAD وworking tree/index.
2. ينفذ Preparation أولًا من `work/rc-1-preparation`: يقبل Blueprint/Plan، ينشئ أو يحدّث `SLUG_PACKAGE_REFERENCE.md` في جذر الحزمة كـcanonical Package Reference وفق `std-package-building` و`std-library-presentation`، ويكمل release-facing `README.md` و`CHANGELOG.md` و`SECURITY.md` عند لزوم RC1. بعد ذلك ينقل القرارات الدائمة، يحذف Discussion Draft في خطوة الإغلاق المناسبة، ثم يدمج PR #2 إلى `phase-draft/rc-1`. توثق هذه الخطة ترتيب البوابة؛ ويثبت تنفيذ artifacts وحالتها في PR الـPreparation Closure، ولا يُفهم من وجودها أن Runtime أو نسخة منشورة موجودة.
3. يتحقق من HEAD الجديد وmerge-base لـ`phase-draft/rc-1` بعد إغلاق Preparation؛ لا يستخدم `work/rc-1-preparation` أو `main` كـimplementation base.
4. ينشئ Work Branch/Execution Batch التنفيذية من ذلك HEAD المحدث، وينفذ WUs بالتتابع في Commits واضحة، دون `amend` أو force-push.
5. يراجع staged paths الصريحة و`git diff --cached --check`.
6. ينشر branch ويفتح Implementation PR إلى `phase-draft/rc-1` وفق الصلاحية المنفصلة؛ لا يفتح PR إلى `work/rc-1-preparation` ولا يدمج إلى `main`.
7. يراجع المساعد القائد remote HEAD وmerge-base وchanged files وchecks وaccumulated diff.

الـPR ليست جزءًا من كل WU؛ هي حد مراجعة Batch. أي remediation بعد Commit تكون Commit جديدة. Merge إلى `main` يظل owner-only.

## 16. Mapping للـOpen Decisions 1–34

هذا mapping يربط كل قرار في Discussion §50 بمسار إنتاجه وإثباته. الاختيارات نفسها مقفولة في Blueprint §50.

| القرار | WU/بوابة التنفيذ | Evidence |
|---:|---|---|
| 1 | WU-03 وWU-07 | real integration عبر direct PDO/`pdo_mysql` على MySQL-compatible verification target يحقق capabilities §11؛ لا drivers إضافية |
| 2 | WU-02 | stateless registry/text factories، built-in/custom registration بدون replacement، وprofile vector names |
| 3 | WU-02 | source generation vectors |
| 4 | WU-02 وWU-04 | exact claim rejection/equivalence tests |
| 5 | WU-02 وWU-06 | lookup invalid/non-lossy system tests |
| 6 | WU-02 | NFC vectors |
| 7 | WU-02 وWU-08 | ICU major 74 وUnicode data 15.1 guard، extension gate، وbyte-identical compatibility vectors لكل PHP 8.x minor داخل `^8.4` ومصفوفة CI (الحالية 8.4/8.5)؛ runtime mismatch هو `SlugRuntimeCompatibilityException` |
| 8 | WU-00 وWU-08 | Composer direct requirements وplatform checks؛ foundation مبكر ثم verification نهائي |
| 9 | WU-02 | security invalid-input suite |
| 10 | WU-02 وWU-04 | length/suffix/collision tests |
| 11 | WU-03 | collation/equality integration assertions |
| 12 | WU-01 وWU-03 | null/empty scope uniqueness tests |
| 13 | WU-01 وWU-03 | identity validation and exact stored representation |
| 14 | WU-03 وWU-04 | package-enforced role/status/current-pointer invariants وunique constraints |
| 15 | WU-03 | placeholder-pointer bootstrap and invariant failure tests |
| 16 | WU-01 وWU-05 | status transition system matrix |
| 17 | WU-03 وWU-05 | per-binding History sequence، operation_id، وtransfer out/in snapshots |
| 18 | WU-05 | current-release rejection and non-current release tests |
| 19 | WU-05 | release-all marker/per-claim atomic assertions |
| 20 | WU-05 | purge precondition/order/erasure tests |
| 21 | WU-05 وWU-07 | same-binding operation×role matrix، alias role transitions، ورفض retired implicit restore |
| 22 | WU-06 | MOVE/PARALLEL source-target atomic tests |
| 23 | WU-05 وWU-07 | existing-target-only transfer، `originalSourceRole` preservation، new/historical/active-alias/retired-alias replacement matrix، exact/generated replacement exclusion، deterministic lock/demote/delete/insert order، current/non-current race matrix، current-only `sourceReplacementResult` cross-field invariants، و`AtomicTransferResultDTO` nested source/target evidence |
| 24 | WU-01 وWU-03 وWU-05 وWU-07 | absent-vs-existing/RELEASED expectedRevision validation، lock order/CAS stale writer tests |
| 25 | WU-03 وWU-07 | owned transaction/savepoint/outer rollback tests |
| 26 | WU-01 وWU-03 وWU-05 وWU-06 وWU-07 | evidence عند وجود key فقط، canonical JSON request version 1 وSHA-256، first-create participant-after-binding sequencing، Result Snapshot JSON v1 exact result_type/DTO mapping وnested field order/flags، round-trip لكل mutation/transition/transfer/adoption، current atomic transfer nested replay invariants، JSON/column metadata equality، malformed/unknown schema rejection، immutable original snapshot، replay بعد تغير live state مع replayed=true دون current-state reconstruction، no-key natural mutation، وretention/purge tests |
| 27 | WU-01 وWU-02 وWU-06 | exact interface/factory/Engine signatures، internal Slug creation، Command/Criteria/DTO fields/types/nullability، shared PageRequest/PageResult types، multi-binding aggregates، وpublic contract review في §5.1.1 و§35.1–§35.6 |
| 28 | WU-06 | explicit adoptCurrent/adoptHistorical/adoptAlias preconditions لكل Binding status، role/status/revision/history، timezone-to-UTC/microsecond/range validation، profile compatibility، وAdoptionResultDTO |
| 29 | WU-00 وWU-08 | direct runtime/dev dependency resolution against stable constraints؛ foundation مبكر ثم latest/lowest verification نهائي |
| 30 | WU-03 وWU-07 | schema/index وoperations evidence وreal concurrency proof |
| 31 | WU-03 وWU-05 وWU-06 | Clock UTC microsecond snapshot tests وimported DateTimeImmutable timezone/range tests |
| 32 | §2.2 و§15 | exact adoption SHA and no mid-train refresh |
| 33 | WU-03 وWU-04 وWU-07 | first-use same/mismatch profile race |
| 34 | WU-00..WU-08 و§15 وPreparation Closure Gate | root `SLUG_PACKAGE_REFERENCE.md` canonical package reference، release-facing README/CHANGELOG/SECURITY، ثم Discussion deletion، updated `phase-draft/rc-1` source، Implementation Batch/PR topology، Batch acceptance وPhase Integration Gate |

## 17. ما لا يدخل RC1 Implementation Batch

هذه العناصر ليست gaps يجب ملؤها داخل WUs:

- PostgreSQL/SQLite أو أي DB adapter غير `pdo_mysql`؛ ولا تُفهم MariaDB كـsupported أو unsupported بالاسم، بل تُقبل فقط إذا حققت capabilities الموثقة عبر `pdo_mysql`، دون ادعاء اختبار كل إصداراتها.
- profile migration أو تغيير profile لـpopulated Scope.
- full URL/path/router/HTTP/SEO/framework integrations وautomatic title monitoring.
- Host entity persistence/existence/auth/authorization/Admin UI.
- generic ETL، batch/dry-run adoption، أو distributed lock خارج MySQL-compatible transaction.
- event dispatcher كشرط core أو package-local ordering/pagination replacement.
- Stable tag/Release/Packagist publication وقرار Merge إلى `main`.
- Real Host Validation في مشروعين مستقلين؛ هذا شرط first Stable release بعد RC1 وفق release controls.

### 17.1 Preparation Closure Gate قبل حذف Discussion

هذه ليست Runtime WU، لكنها شرط إغلاق Preparation وDecision #34، وتنفذ قبل حذف `docs/SLUG_LIBRARY_RC_CONCEPT_DISCUSSION.md`:

1. ينشئ المالك أو يحدّث `SLUG_PACKAGE_REFERENCE.md` في جذر الحزمة وفق Package Building/Library Presentation Standards، ويجعله المرجع canonical package-facing للتثبيت والاستعمال ومسارات construction العامة، public contracts، PHP/DB/ICU support، ownership/lifecycle/pagination boundaries، وحالة RC1 الحالية دون future-state claims.
2. يحدّث release-facing `README.md` و`CHANGELOG.md` و`SECURITY.md` بالقيم الحالية المطلوبة لـRC1 وفق Library Presentation Standard، مع فصل حالة العقد عن حالة التنفيذ والنشر.
3. بعد مراجعة artifacts ومطابقة source-of-truth، تنقل القرارات إلى Blueprint/Plan، ثم تحذف Discussion Draft، ثم تتحقق أن `SLUG_PACKAGE_REFERENCE.md` هو المرجع الجذري package-facing وأن Blueprint supporting architecture وPlan execution gates لا يناقضانها.
4. بعد هذا الترتيب فقط يدمج المالك PR #2 إلى `phase-draft/rc-1`، ويبدأ implementation branch من HEAD المحدث. لا تستخدم Package Reference gate لتوسيع RC1 إلى Stable tag/Release/Packagist.

لا تمنع هذه الحدود adapters أو release work لاحقة، لكنها لا تدخل acceptance الحالية ولا تغير identity/ownership model.

## 18. معيار التقرير النهائي للتنفيذ اللاحق

يجب أن يعرض التقرير، دون إعادة نسخ الخطة:

```text
source branch/SHA وstarting state
Work Branch وCommits بالترتيب
exact changed files وstaged checks
WU acceptance وtest counts
MySQL-compatible/transaction/concurrency evidence
PHPStan max وComposer/CI gates
Harness run 1/run 2 وproduction autoload
Remote HEAD وmerge-base مع phase-draft/rc-1
PR state إن أنشئت
ما بقي خارج RC1 وأي blocker مثبت
```

لا يصف التقرير test غير المشغل أو infrastructure block كـpassed، ولا يدعي independent verification أو final acceptance دون صاحب الصلاحية والدليل المطلوب.
