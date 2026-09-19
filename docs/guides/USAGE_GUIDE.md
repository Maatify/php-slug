# Maatify Slug — Usage Guide

> **Publication state:** Development / Unpublished
>
> `SLUG_PACKAGE_REFERENCE.md` هو العقد التقني المعياري. يشرح هذا الدليل طريقة الاستخدام ولا ينشئ عقدًا بديلًا.

## Fit / When to use

استخدم `maatify/php-slug` عندما تحتاج الحزمة إلى توليد Slug أو canonicalization أو امتلاك scoped مستمر مع resolution وHistory. المسار stateless مناسب للنصوص التي لا تحتاج إلى Persistence، والمسار persisted مناسب عندما تملك الحزمة lifecycle والـclaims الخاصة بها.

## Requirements

- PHP `^8.4`.
- `ext-intl`, `ext-mbstring`, `ext-pdo`, و`ext-pdo_mysql`.
- المسار stateless يحتاج إلى production Composer autoload فقط.
- المسار persisted يحتاج إلى `PDO` MySQL-compatible وschema الحزمة. يستخدم التحقق المحلي MySQL disposable عبر `compose.integration.yml`.

## Publication state

الحزمة **Development / Unpublished**. لا توجد حاليًا قناة توزيع عامة أو أمر تثبيت public صالح؛ إعداد التطوير موثق في [`CONTRIBUTING.md`](../../CONTRIBUTING.md). لا تُعرض `composer require maatify/php-slug` على أنها طريقة استهلاك حالية.

## Non-goals / Host boundaries

الحزمة لا تملك Host entity persistence أو entity existence أو authentication أو authorization أو routing أو URL construction أو HTTP أو SEO أو Framework integration. يملك Host اتصال `PDO` وتهيئته، ويمرر `ReservedSlugPolicyInterface` و`ClockInterface`، ويحدد هوية الكيان واحتياجات التطبيق.

لا يتعامل المستهلك مع جداول الحزمة أو repositories أو SQL كـPublic API. ارجع إلى [Package Reference](../../SLUG_PACKAGE_REFERENCE.md) للعقد الكامل وحدود الملكية.

## Construction paths

### Stateless canonicalization

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
```

هذا المسار لا ينشئ اتصالًا أو يقرأ إعدادات Host. المثال التنفيذي هو [`examples/canonicalization.php`](../../examples/canonicalization.php).

### Persisted lifecycle

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
```

ينشئ Host الـ`PDO` ويطبق schema الحزمة ضمن بيئته. المثال التنفيذي [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) يوضح التهيئة والـcleanup عبر بيئة Integration canonical.

## Capability map

| Capability | Public API | Walkthrough | Example |
|---|---|---|---|
| Canonicalization / generation | `SlugTextServiceInterface` — `generateFromSource`, `canonicalizeClaim`, `canonicalizeLookup` | [Canonicalization walkthrough](#canonicalization-walkthrough) | [`examples/canonicalization.php`](../../examples/canonicalization.php) |
| Lifecycle ownership / mutation | `SlugEngine` و`SlugLifecycleServiceInterface` — `assignExact` ومسار lifecycle الم persisted | [Persisted lifecycle walkthrough](#persisted-lifecycle-walkthrough) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Consumer reads | `checkAvailability`, `getCurrent`, `resolve` | [Consumer reads / resolution](#consumer-reads--resolution) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Management / Operational Read | `SlugManagementQueryInterface` — `getBinding`, `getCurrent`, `listAliases`, `getHistory`, `inspectRegistry`, `inspectScope`, `searchBindings`, `searchRegistry` | [Management operational reads](#management-operational-reads) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) عبر `getBinding` |

هذا map يوجه القارئ إلى الاستخدام؛ لا يكرر inventory الكامل الموجود في Package Reference.

## Canonicalization walkthrough

**Input**

النص `Hello, World!` و`SlugProfileKey('ascii-v1')`.

**Public Call**

`SlugTextServiceInterface::generateFromSource(...)`.

**Result**

يعاد `GeneratedSlugDTO` بقيمة `hello-world`. يتحقق المثال من النتيجة ويفشل بـ`RuntimeException` إذا اختلفت.

**Boundary**

لا توجد Persistence أو transaction في هذا المسار؛ لا يحتاج المثال إلى Docker أو Database. قواعد Profile والـcanonicalization المعيارية في [Package Reference §5](../../SLUG_PACKAGE_REFERENCE.md#5-الهوية-والنص).

## Persisted lifecycle walkthrough

### Lifecycle ownership / mutation

**Input**

`SlugScope(namespace: 'example', localeKey: null, contextKey: null)`، و`SlugProfileKey('ascii-v1')`، وهوية `article/example-1`، وcandidate `hello-world`، و`AuditContextDTO` صالحة.

**Public Call**

`SlugEngine::assignExact(new AssignExactCommand(...))`.

**Result**

يعاد `SlugMutationResultDTO` وتكون قيمة current slug هي `hello-world`.

**Boundary**

يمر الاستدعاء عبر `SlugEngine` ثم lifecycle service ثم Persistence المملوكة للحزمة. لا يعيد Host تنفيذ claim أو uniqueness أو History.

### Consumer reads / resolution

**Input**

نفس `ScopeProfileRequestDTO` وsegment `hello-world` بعد نجاح assignment.

**Public Call**

`SlugEngine::resolve(new ResolutionCriteria(...))`، ويمكن استخدام `checkAvailability` و`getCurrent` للاستعلامين الآخرين.

**Result**

يعاد `SlugResolutionDTO` يطابق current claim ويشير إلى `article/example-1`.

**Boundary**

هذه reads لا تمنح claim؛ `checkAvailability` advisory، بينما claim الفعلية يحسمها مسار mutation والـunique Registry constraint.

## Management operational reads

**Input**

هوية Binding نفسها بعد assignment.

**Public Call**

`SlugEngine::getBinding(new BindingCriteria($bindingIdentity))`.

وتستخدم بقية management operations criteria العامة الخاصة بها عند الحاجة: `getCurrent` و`listAliases` و`getHistory` و`inspectRegistry` و`inspectScope` و`searchBindings` و`searchRegistry`.

**Result**

يعاد `BindingDTO` من `getBinding`، وتظهر current slug كـ`hello-world` في المثال persisted.

**Boundary**

هذه operational reads موجهة إلى Host أو أدوات الإدارة ولا تعيد تعريف public lifecycle contract. pagination تعتمد الأنواع المشتركة التي يحددها Package Reference.

## Transactions / concurrency boundary

عندما لا يملك Host outer transaction، تملك الحزمة transaction الخاصة بالعملية وتنفذ commit أو rollback وفق العقد. عندما يمرر Host outer transaction، تشارك الحزمة فيه وتستخدم savepoint عند capability المطلوبة ولا تتولى commit أو rollback للـouter transaction.

في exact-claim race تكون unique Registry constraint هي authority النهائية، ويعاد conflict semantic وفق الاستثناء العام المناسب. تستخدم lifecycle mutations revision/CAS وidempotency عند توفير idempotency key. لا تغيّر الحزمة timezone العام؛ يستخدم المثال Clock في UTC.

الـCompose lifecycle canonical هو نفسه المستخدم في Integration وSystem وConsumer Harness وpersisted example؛ لا توجد دورة خدمة ثانية للمثال.

## Errors / exceptions

تستخدم الحزمة `SlugExceptionInterface` وfamilies domain العامة مع concrete exceptions مثل `SlugAlreadyClaimedException` و`SlugRevisionConflictException` و`SlugReservedException` و`SlugTransactionParticipationException`. يجب أن يترك Host هذه النتائج للعقد المناسب بدل فحص SQL أو التقاط `Throwable` لتكوين contract جديدة. راجع [Package Reference §12](../../SLUG_PACKAGE_REFERENCE.md#12-exception-contract).

## Examples navigation

- [`examples/canonicalization.php`](../../examples/canonicalization.php) — stateless generation.
- [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) — assignment وresolution وmanagement read على MySQL disposable.

تشغيل المثالين محليًا ضمن gate واحدة:

```bash
bash tools/ci/run-gate.sh examples-smoke
```

يشغل الـgate stateless example ثم persisted example عبر Compose lifecycle canonical. المثال persisted لا يعمل كـstandalone process خارج هذه البيئة لأنه يتطلب `SLUG_TEST_DB_*`.

## Further documentation

- [Package Reference](../../SLUG_PACKAGE_REFERENCE.md) — المصدر المعياري للعقد وPublic API.
- [CONTRIBUTING.md](../../CONTRIBUTING.md) — إعداد التطوير والـquality gates.
- [CHANGELOG.md](../../CHANGELOG.md) — تاريخ التغييرات وحالة النشر.
- [SECURITY.md](../../SECURITY.md) — سياسة الأمان وحدودها.
