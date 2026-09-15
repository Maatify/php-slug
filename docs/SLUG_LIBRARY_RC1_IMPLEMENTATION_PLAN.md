# Maatify Slug — RC1 Implementation Plan

> **الحالة:** خطة تنفيذ مرتبطة بـ`SLUG_LIBRARY_RC1_BLUEPRINT.md`؛ لا تثبت أن أي Work Unit نُفذت.
>
> **المرجع:** `docs/SLUG_LIBRARY_RC1_BLUEPRINT.md`
> **RC1 source baseline المعتمد للتأليف:** `006ca7c62b4defc62c8ef2b16374b6f60d48a8dc`
> **الـPhase Draft:** `phase-draft/rc-1`
> **Preparation Work Branch:** `work/rc-1-preparation`

هذه الخطة تحول الـBlueprint إلى dependency graph وExecution Batch وWork Units قابلة للتسليم والمراجعة. نطاق هذه المهمة الحالية هو تأليف الوثيقتين فقط؛ لا تنشئ هذه الخطة Runtime أو Schema أو Tests أو CI أو Composer files.

## 1. النتيجة المستهدفة

إنتاج RC1 مكتملة العقد التالية، مع إبقاء الحزمة مستقلة وHost-agnostic:

```text
Profile/Text
→ Scope + EntityReference
→ PDO MySQL persistence
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

تبدأ Preparation من `phase-draft/rc-1` عبر `work/rc-1-preparation` وتغلق أولًا بعد قبول Blueprint/Plan، ونقل القرارات الدائمة، وحذف Discussion Draft في خطوة الإغلاق المناسبة، ثم دمج PR #2 إلى `phase-draft/rc-1`. يبدأ التنفيذ اللاحق فقط من HEAD المحدث المتحقق منه لـ`phase-draft/rc-1`؛ لا يستخدم `work/rc-1-preparation` كـimplementation base ولا يستخدم `main` كبديل ولا يصلح ancestry تلقائيًا.

### 2.2 Standards snapshot

يستخدم RC1 snapshot adoption المحلي عند `2fc57f9320f8a7f7147fb20abbcfa311fdf40c28` كما هو مسجل في `STANDARDS_MANIFEST.md`. المعايير السبعة المنطبقة هي:

| Standard | Version | موضع التطبيق في الخطة |
|---|---:|---|
| `std-package-building` | 1.3.0 | architecture، PDO، exceptions، PHPStan، persistence |
| `std-composer-package` | 1.2.0 | dependency/autoload/scripts/validation |
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
        [قبول Blueprint/Plan، نقل القرارات، حذف Discussion Draft، دمج PR #2]
    └── work/rc-1-implementation (لاحقًا من HEAD المحدث لـphase-draft/rc-1)
        └── PR إلى phase-draft/rc-1 (لاحقًا عند فتحها)
```

هذه ليست مساواة بين المصطلحات: `Phase != Branch != PR`. الـdefault هو Batch وWork Branch وPR واحدة لأن العقود وschema وlocks وtests مشتركة. Commits logical milestones تكفي لتتبع WUs؛ لا توجد PR لكل WU لمجرد الرقم. لا تفتح Implementation PR إلى `work/rc-1-preparation`.

Branch التنفيذ المقترحة لا تُنشأ في هذه المهمة. إن قرر المالك استخدامها لاحقًا، تبدأ بعد إغلاق Preparation من أحدث HEAD متحقق لـ`phase-draft/rc-1`، لا من `work/rc-1-preparation` ولا من `main`. Merge إلى `main` وTag وRelease وPublish تبقى owner-only.

## 3. Dependency graph وExecution Waves

### 3.1 الرسم

```text
WU-01 Contracts/Identity/Profile SPI/Exceptions
        │
        ├── WU-02 Built-in Profiles/Unicode/Security/Length
        │
        └── WU-03 MySQL Schema/PDO/Scope Bootstrap/Transactions
                 │
                 └── WU-04 Registry Claim/Allocation/Availability
                          │
                          └── WU-05 Lifecycle/Alias/History/Release/Transfer
                                   │
                                   ├── WU-06 Transition/Adoption/Resolution/Management
                                   │
                                   └── WU-07 System/Concurrency/Transaction test evidence
                                            │
                                            └── WU-08 Harness/Package Gates/Full Integration Gate
```

### 3.2 Waves

- **Wave 1:** `WU-01` ثم `WU-02`؛ profile contracts لازمة قبل persistence.
- **Wave 2:** `WU-03` بعد WU-01؛ WU-03 لا يبدأ قبل تثبيت type/exception/identity contracts.
- **Wave 3:** `WU-04` بعد WU-02 وWU-03؛ claim يعتمد profile canonicalization وschema uniqueness.
- **Wave 4:** `WU-05` بعد WU-04؛ lifecycle يعتمد claim primitives وregistry roles/history sequences.
- **Wave 5:** `WU-06` بعد WU-05؛ transition/adoption/resolve/management يعتمد live model المكتمل.
- **Wave 6:** `WU-07` يتحرك مع كل WU في صورة evidence vertical، ويغلق بعد WU-06 بمصفوفة كاملة.
- **Wave 7:** `WU-08` بعد كل runtime وtest behavior؛ يثبت consumer وpackage/CI gates.

لا يوجد توازٍ داخل WUs هنا: جميعها تشترك في public contracts وschema وtransaction semantics. إذا أثبت التنفيذ ownership مستقلة لاحقًا، يجوز للمساعد القائد إعادة توزيع جزء صغير داخل Batch مع الحفاظ على نفس gates؛ لا يغير ذلك Blueprint.

## 4. Work Unit WU-01 — Public domain contracts

### 4.1 Owned paths

```text
src/Identity/
src/Scope/
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

تنفيذ value objects `Slug`, `SlugProfileKey`, `SlugScope`, `EntityReference`، والعقود العامة ذات signatures المحددة في Blueprint §5.1.1، وكل Commands/Criteria/DTOs/Enums/aggregated results في §35، وexception marker/taxonomy في §36. تتحقق Commands من input contract فقط ولا تنفذ orchestration.

### 4.3 Acceptance criteria

- كل interface/enum/DTO/exception يطابق suffix والنطاق العام.
- `SlugScope` identity لا تحتوي profile، و`EntityReference` لا تطبع أو تفسر قيم Host.
- nullable locale/context يميز بين `null` وempty string وفق §9.
- Commands الموجودة لا تقبل internal IDs بدل domain identity إلا حيث نص Blueprint.
- expected revision وidempotency/audit fields لها validation محددة.
- `ScopeProfileRequestDTO` وBinding identity وall result aggregates لها fields/types/nullability محددة، ولا توجد operation أو public type تُترك لقرار أثناء التنفيذ.
- `transitionScope` و`atomicTransfer` يعيدان aggregates المحددة في §35 مع source/target before/after/revision/result.
- exception parent لكل package family محدد باسم exact published class في `maatify/exceptions` كما في §36.
- Exceptions تستند إلى stable `maatify/exceptions` hierarchy ولا تبتلع Throwable.

### 4.4 Evidence

Unit tests لكل validation boundary، JSON snapshots لكل DTO، واختبارات أن Criteria ليست DTO وأن Commands لا تنفذ persistence. لا يُقبل WU-01 إذا احتاج WU لاحقة إلى إعادة تسمية public type.

## 5. Work Unit WU-02 — Built-in profiles

### 5.1 Owned paths

```text
src/Profile/
src/Generation/
src/Canonicalization/
src/Validation/
src/Text/
tests/Unit/Profile/
tests/Unit/Generation/
tests/Unit/Canonicalization/
tests/Unit/Validation/
```

### 5.2 المسؤولية

تنفيذ `unicode-v1` و`ascii-v1`، Profile registry، الفصل بين source/claim/lookup، security policy، ICU compatibility، code-point length، suffix preparation، وreserved-policy SPI دون persistence.

### 5.3 Acceptance criteria

- لا يتغير output profile مضمن بصمت؛ registry يمنع duplicate built-in key.
- `unicode-v1` يستخدم NFC وUnicode lowercase وallowed set `L/M/Nd/U+002D`، ويبقي Arabic.
- `ascii-v1` يستخدم ICU ID `Any-Latin; Latin-ASCII` ويقبل ICU major 74 فقط.
- exact claim لا يحول spaces/punctuation، وlookup لا يطبق generation-only transforms.
- invalid UTF-8/NUL/Cc/Cs/Cf/path separators ترفض قبل lossy transform.
- max 160 code points؛ exact الطويل يرفض؛ generated suffix يحجز الطول.
- base ثم `-2` إلى `-1000` فقط؛ exhaustion semantic exception.
- vectors §38 كلها ناجحة، ومن ضمنها `hello!!!` في lookup invalid لا `hello`.

### 5.4 Evidence

Property tests لـidempotence، data-driven vectors لكل Profile، runtime extension-missing tests التي تفشل مغلقًا، وtests تثبت أن source وclaim وlookup ليست aliases مخفية لبعضها.

## 6. Work Unit WU-03 — MySQL schema وPDO boundary

### 6.1 Owned paths

```text
schema/mysql/README.md
schema/mysql/001_slug_rc1.sql
src/Infrastructure/Persistence/PDO/
src/Persistence/Contract/
src/Scope/Persistence/
tests/Integration/Schema/
tests/Integration/Persistence/
```

هذه paths مستقبلية ضمن تنفيذ RC1؛ لا تُنشأ في مهمة التأليف الحالية.

### 6.2 المسؤولية

إنشاء schema وفق Blueprint §12–§14، direct PDO repositories/row hydration، MySQL driver guard، scope first-use/bootstrap، package-owned transaction/savepoint coordinator، وClock persistence adapter.

### 6.3 Acceptance criteria

- schema يعمل على MySQL 8.0.36 فقط ويحتوي prefix `maa_slug_` وجميع indexes/checks/FKs package-local.
- `current_registry_id` nullable بلا circular FK، وplaceholder sequence §14 قابل للتنفيذ atomic.
- `utf8mb4_bin` و`ascii_bin` موجودتان حيث قررهما Blueprint، ولا تعتمد schema على collation normalization.
- `current_marker` يفرض current claim واحدة لكل Binding، و`uk_registry_scope_slug` authority نهائية.
- `maa_slug_operations` يحتفظ بـoperation identity وoperation type وfingerprint وversioned result snapshot، و`maa_slug_operation_bindings` يثبت participants وunique replay lookup للـsingle/source/target.
- replay يقرأ snapshot الأصلي ولا يعيد بناء DTO من current state؛ retention وpurge يطبقان §12.5 و§29، مع FK/index names المحددة.
- driver يرفض أي DB غير MySQL 8.0.36 بعقد واضح، ولا يضيف SQLite fallback.
- PDO config وunique placeholders وint LIMIT/OFFSET وmixed-row annotations مطبقة.
- duplicate conversion محصورة في MySQL `errorInfo[1] === 1062` مع constraint context.
- package-owned transaction rollback وcaller savepoint setup يحدثان قبل mutation.

### 6.4 Evidence

Real MySQL schema install، constraint violation tests، pointer bootstrap tests، profile first-use race، direct PDO integration، cleanup/repeatability مرتان، وtransaction evidence في §10.

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
- same-binding current/history/alias/retired لا يعامل cross-binding collision وفق §22.
- reservation blocks new ownership and target transfer، ولا invalidates retained same-binding ownership.
- availability يعيد classifications الخمس ولا ينشئ state ولا يعد بضمان race-free.
- Registry row وcurrent pointer وBinding revision لا تتجزأ عند failure.
- concurrent exact claim يخرج winner واحدًا وsemantic loser؛ concurrent auto allocation ينتج base/suffix deterministic.

### 7.4 Evidence

System workflows من public API لـassign exact/generated، real MySQL race workers، vectors للأطوال، same slug في Scopes مختلفة، وavailability-vs-claim race.

## 8. Work Unit WU-05 — Lifecycle وaliases وhistory وrelease وtransfer

### 8.1 Owned paths

```text
src/Lifecycle/
src/Alias/
src/History/
src/Transfer/
src/Maintenance/
src/Internal/Transaction/
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
- alias retirement لا يحرر slug؛ reactivation/promote يطبقان role rules الدقيقة.
- releaseClaim يرفض current؛ releaseAll يكتب per-claim snapshots وmarker ثم يضع RELEASED/pointer NULL.
- purge يعمل فقط لـRELEASED بلا claims، ويحذف History ثم Binding ويترك Scope.
- transfer ينقل الأدوار الأربع، وcurrent يحتاج source replacement؛ target role/state/reservation rules ثابتة.
- transfer يكتب out/in snapshots في transaction واحدة وبـ`operation_key` واحدة، ولا تظهر unowned gap.
- history sequence وrevision يزدادان بشكل صحيح، وresult aggregate يحفظ source/target before-after states والـrevisions والـevents.
- idempotency key/fingerprint والـresult snapshot تُحفظ في operations evidence؛ transfer/transition يستخدمان operation واحدًا ومشاركي SOURCE/TARGET، ولا يكتفيان بإعادة قراءة current state.

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
src/Infrastructure/Persistence/PDO/Query/
tests/Unit/Resolution/
tests/Integration/Adoption/
tests/Integration/Management/
tests/System/Resolution/
tests/System/Transition/
```

### 9.2 المسؤولية

تنفيذ `transitionScope` بوضعَي MOVE/PARALLEL، adoption commands، direct resolution DTO، availability public query، management Criteria/page adapters، وdelegation إلى `maatify/persistence` pagination.

### 9.3 Acceptance criteria

- transition ينشئ target Binding ولا يغير source `scope_id` أو source Registry snapshots.
- MOVE يجعل المصدر INACTIVE، وPARALLEL لا يغير source status؛ كلاهما atomic.
- transition result هو `ScopeTransitionResultDTO` وفيه participant results وsource/target before-after/revisions والـHistory؛ transfer result هو `AtomicTransferResultDTO` بنفس الصراحة.
- target profile يطبق على target claim، وprofile mismatch يفشل قبل mutation.
- adoptCurrent/adoptHistorical/adoptAlias لها preconditions منفصلة للـabsent/RELEASED/ACTIVE/INACTIVE وrole/status/revision/history في Blueprint §31، وتمر بنفس canonical/profile/ownership/reservation rules وتتحقق من UTC timestamp.
- resolve يفصل `matchKind`, `bindingStatus`, `inputFormCanonicality` ويشير إلى current مباشرة.
- released claim لا تحل، وretained history لا تظهر كlive ownership.
- Criteria page/perPage/sort limits ثابتة، count/data predicates متطابقة، tie-breaker `id ASC`.
- pagination mechanics delegated إلى stable `Maatify\\Persistence\\Pdo\\Pagination` بلا local paginator.

### 9.4 Evidence

System resolution matrix، transition source/target races، adoption compatibility/rejection، real management searches، pagination count/order tests، وassertion أن queries لا JOIN Host.

## 10. Work Unit WU-07 — Required behavioral evidence

### 10.1 Owned paths

```text
tests/Unit/
tests/Integration/
tests/System/
tests/Fixtures/
tests/Support/
phpunit.xml.dist
```

تظل كل test في WU المالكة للسلوك عند الإمكان؛ WU-07 يملك cross-cutting orchestration وfixtures وreal-race runners فقط، ولا يفصل Runtime/tests إلى PRs مصطنعة.

### 10.2 Unit evidence

- Identity/Scope/ProfileKey/EntityReference validation.
- Profile vectors، idempotence، invalid/security input، length/suffix.
- Commands/Criteria validation وexception taxonomy.
- Pure reservation and availability classification.
- DTO serialization وEnum mappings.

### 10.3 Integration evidence

مع اتصال PDO حقيقي إلى MySQL 8.0.36:

- schema creation/constraints/indexes/collations، بما فيها `maa_slug_operations` و`maa_slug_operation_bindings` وresult snapshots؛
- scope first use/profile mismatch/current-pointer bootstrap؛
- Registry roles and uniqueness؛
- Clock UTC `DATETIME(6)` وHistory snapshots/sequences وoperation evidence/replay retention؛
- package-owned transaction and caller savepoint participation؛
- management count/data/pagination؛
- cleanup/repeatability and no host table access.

### 10.4 System evidence

كل workflow يبدأ من public command/query ويصل إلى DTO/result أو semantic exception:

```text
assignExact → changeGenerated → resolve(old historical/new current)
assignGenerated collision → suffix
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
- same idempotency key with same/different fingerprint، بعد تغير live state، يعيد snapshot أو conflict دون mutation جديدة؛
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

## 11. Work Unit WU-08 — Composer/CI/Harness gates

### 11.1 Owned paths لاحقًا

```text
composer.json
phpstan.neon
.php-cs-fixer.php
phpunit.xml.dist
tools/
.github/workflows/
tests/Consumer/
```

لا تُنشأ هذه الملفات في مهمة Blueprint الحالية.

### 11.2 Composer contract

عند تنفيذ WU-08 يضاف مباشرة:

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

يستخدم `maatify/persistence` فقط للـpagination stable API، ولا يضاف package-local replacement. لا يضاف `composer.lock` أو `version` أو custom repository في الحزمة القابلة للنشر. `composer validate --strict` و`composer check-platform-reqs` إلزاميان.

### 11.3 PHPStan max

يكون `phpstan.neon` على `level: max` ويشمل `src` و`tests` عند وجودها. الأمر المطلوب:

```bash
vendor/bin/phpstan analyse src tests --level=max
```

لا baseline ولا `ignoreErrors` ولا inline suppression لإخفاء خطأ. أي mismatch في DTO generics أو PDO hydration يصلح في Runtime/type annotations.

### 11.4 CI contract

التنفيذ اللاحق يضيف stable aggregate gate fail-closed ويغطي، حسب applicability:

```text
composer validate --strict
dependency resolution latest
dependency resolution lowest on PHP 8.4
composer check-platform-reqs
PHP syntax
PHPStan max
code-style dry-run when configured
git diff --check / whitespace
complete PHPUnit suites
real MySQL Integration
Consumer Verification Harness
composer audit --no-interaction --abandoned=fail
workflow lint
```

لا `continue-on-error` أو `|| true` أو silent skip. MySQL service image يثبت `mysql:8.0.36`، readiness health check، credentials مؤقتة، وpermissions `contents: read` فقط ما لم يعتمد سبب أضيق/مختلف.

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
→ construct public services with injected PDO/Clock/Policy
→ assign/change/resolve workflow
→ observable DTO/result
→ cleanup package DB state
```

ينفذ Harness مرتين من consumer state وdatabase state نظيفين، ويثبت عدم الاعتماد على vendor/generated state سابق. إذا كانت persistence مطلوبة، يستخدم MySQL 8.0.36 الحقيقي لا SQLite/mock.

## 13. Acceptance criteria على مستوى Execution Batch

لا تغلق Batch إلا إذا تحققت جميع الشروط الآتية:

- كل WU من WU-01 إلى WU-08 مكتملة وفق acceptance الخاصة بها أو لها تصنيف evidence دقيق.
- كل public contract في Blueprint موجود بلا تغيير غير معتمد.
- risk gates R1 إلى R7 في Blueprint §39 لها evidence مستقل.
- unit/integration/system/concurrency/transaction tests ناجحة؛ لا يعتبر unrun أو blocked نجاحًا.
- Consumer Verification Harness نجح مرتين من clean states، ومعه production autoload proof.
- MySQL 8.0.36 هو DB evidence الوحيد المعلن، ولا توجد portability claims إضافية.
- PHPStan max وComposer validation/platform/audit وباقي required gates ناجحة.
- `git diff --check` وstaged diff checks نظيفة، وchanged files داخل Work Branch scope.
- direct review راجعت accumulated diff مقابل أحدث base، وبعد أي remediation تجرى Fresh Full Acceptance Review.

## 14. Phase Integration Gate

عند إغلاق RC1 Full Lifecycle Batch تشغل Full Applicable Verification Set، لا subset انتقائي فقط:

1. Composer strict validation وlatest/lowest dependency resolution.
2. PHP 8.4 minimum compatibility، syntax، PHPStan max، style/whitespace.
3. كل Unit/Regression/System suites، مع failure propagation.
4. MySQL 8.0.36 real Integration، cleanup، repeatability.
5. transaction/savepoint وreal concurrency evidence.
6. Consumer Verification Harness gate.
7. Composer audit وworkflow lint عندما توجد workflows.
8. architecture/scope/public-contract review وBlueprint/Plan consistency.

الـaggregate gate يفحص results لكل upstream job؛ unexpected skipped أو cancelled أو failure يفشل gate. documentation-only current task لا تشغل هذه المصفوفة لأنها لا تنتج Runtime، لكن Implementation Batch لا تتجاوزها.

## 15. Commit/Push/PR procedure للتنفيذ اللاحق

هذه الخطة لا تنفذ الإجراء الآن. عند التصريح بتنفيذ RC1:

1. يتحقق المنفذ من source branch وexact HEAD وworking tree/index.
2. ينفذ Preparation أولًا من `work/rc-1-preparation`: يقبل Blueprint/Plan، ينقل القرارات الدائمة، يحذف Discussion Draft في خطوة الإغلاق المناسبة، ثم يدمج PR #2 إلى `phase-draft/rc-1`.
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
| 1 | WU-03 وWU-07 | MySQL 8.0.36 real integration؛ لا drivers إضافية |
| 2 | WU-02 | registry/profile vector names |
| 3 | WU-02 | source generation vectors |
| 4 | WU-02 وWU-04 | exact claim rejection/equivalence tests |
| 5 | WU-02 وWU-06 | lookup invalid/non-lossy system tests |
| 6 | WU-02 | NFC vectors |
| 7 | WU-02 وWU-08 | ICU major guard وextension gate |
| 8 | WU-08 | Composer direct requirements وplatform checks |
| 9 | WU-02 | security invalid-input suite |
| 10 | WU-02 وWU-04 | length/suffix/collision tests |
| 11 | WU-03 | collation/equality integration assertions |
| 12 | WU-01 وWU-03 | null/empty scope uniqueness tests |
| 13 | WU-01 وWU-03 | identity validation and exact stored representation |
| 14 | WU-03 وWU-04 | role CHECK/current-marker/unique constraints |
| 15 | WU-03 | placeholder-pointer bootstrap and invariant failure tests |
| 16 | WU-01 وWU-05 | status transition system matrix |
| 17 | WU-03 وWU-05 | per-binding History sequence، operation_id، وtransfer out/in snapshots |
| 18 | WU-05 | current-release rejection and non-current release tests |
| 19 | WU-05 | release-all marker/per-claim atomic assertions |
| 20 | WU-05 | purge precondition/order/erasure tests |
| 21 | WU-05 | alias role transition and generated restore tests |
| 22 | WU-06 | MOVE/PARALLEL source-target atomic tests |
| 23 | WU-05 وWU-07 | current/non-current transfer race matrix وAtomicTransferResultDTO source/target evidence |
| 24 | WU-03 وWU-05 وWU-07 | lock order/CAS stale writer tests |
| 25 | WU-03 وWU-07 | owned transaction/savepoint/outer rollback tests |
| 26 | WU-01 وWU-03 وWU-05 وWU-06 | operations evidence schema، fingerprint، participant lookup، immutable result snapshot، replay بعد تغير live state، وretention/purge tests |
| 27 | WU-01 | exact interface signatures، Command/Criteria/DTO fields/types/nullability، multi-binding aggregates، وpublic contract review في §5.1.1 و§35.1–§35.6 |
| 28 | WU-06 | explicit adoptCurrent/adoptHistorical/adoptAlias preconditions لكل Binding status، role/status/revision/history، timestamp/profile compatibility، وAdoptionResultDTO |
| 29 | WU-08 | direct dependency resolution against stable constraints |
| 30 | WU-03 وWU-07 | schema/index وoperations evidence وreal concurrency proof |
| 31 | WU-03 وWU-05 | Clock UTC microsecond snapshot tests |
| 32 | §2.2 و§15 | exact adoption SHA and no mid-train refresh |
| 33 | WU-03 وWU-04 وWU-07 | first-use same/mismatch profile race |
| 34 | WU-01..WU-08 و§15 | Preparation closure، updated `phase-draft/rc-1` source، Implementation Batch/PR topology، Batch acceptance وPhase Integration Gate |

## 17. ما لا يدخل RC1 Implementation Batch

هذه العناصر ليست gaps يجب ملؤها داخل WUs:

- MariaDB/PostgreSQL/SQLite أو أي DB adapter آخر.
- profile migration أو تغيير profile لـpopulated Scope.
- full URL/path/router/HTTP/SEO/framework integrations وautomatic title monitoring.
- Host entity persistence/existence/auth/authorization/Admin UI.
- generic ETL، batch/dry-run adoption، أو distributed lock خارج MySQL transaction.
- event dispatcher كشرط core أو package-local ordering/pagination replacement.
- Stable tag/Release/Packagist publication وقرار Merge إلى `main`.
- Real Host Validation في مشروعين مستقلين؛ هذا شرط first Stable release بعد RC1 وفق release controls.
- README/CHANGELOG/SECURITY/Package Reference release-presentation work إذا لم يطلبها Release Preparation منفصل؛ لا تستخدم هذه الخطة لادعاء Stable support.

لا تمنع هذه الحدود adapters أو release work لاحقة، لكنها لا تدخل acceptance الحالية ولا تغير identity/ownership model.

## 18. معيار التقرير النهائي للتنفيذ اللاحق

يجب أن يعرض التقرير، دون إعادة نسخ الخطة:

```text
source branch/SHA وstarting state
Work Branch وCommits بالترتيب
exact changed files وstaged checks
WU acceptance وtest counts
MySQL/transaction/concurrency evidence
PHPStan max وComposer/CI gates
Harness run 1/run 2 وproduction autoload
Remote HEAD وmerge-base مع phase-draft/rc-1
PR state إن أنشئت
ما بقي خارج RC1 وأي blocker مثبت
```

لا يصف التقرير test غير المشغل أو infrastructure block كـpassed، ولا يدعي independent verification أو final acceptance دون صاحب الصلاحية والدليل المطلوب.
