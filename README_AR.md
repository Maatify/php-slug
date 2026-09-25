# Maatify Slug — الترجمة العربية

> هذا الملف ترجمة عربية غير معيارية لملف [`README.md`](README.md).
> النسخة الإنجليزية هي الوثيقة authoritative وcanonical. عند وجود اختلاف، تكون النسخة الإنجليزية هي المرجع.

<div align="center">

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

محرك مستقل لدورة حياة Slug في حزم PHP، مع حدود واضحة بين نطاق Slug وHost وURL وHTTP وSEO.

</div>

---

## حالة النشر

إن `v1.0.0-rc.1` هي Published Release Candidate متاحة عبر Packagist. وتبقى Pre-Stable، ولا تنشئ Stable support line، ولا يوجد Published Stable release. النشر الفعلي لا يعني الجاهزية للإصدار Stable.

المرجع الحالي للعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). تُحفظ أدلة التنفيذ في سجل GitHub PR وCI بدل تكرارها في ملف README هذا الموجّه للمستهلك.

## ملخص الحزمة

`maatify/php-slug` مكتبة PHP مستقلة لتوليد Slug وCanonicalization والملكية المقيّدة بالنطاق وإدارة دورة الحياة والـAliases والـHistory والـResolution، مع Persistence مملوكة للحزمة عبر PDO MySQL.

## الميزات الأساسية

- **التوليد والـCanonicalization:** توليد وcanonicalization متسقان عبر Profiles مدمجة بإصدارات محددة.
- **الملكية ودورة الحياة:** Exact claiming وgenerated allocation وrelease وscope transition وatomic transfer وadoption.
- **Aliases وHistory:** Aliases نشطة ومتقاعدة مع History غير قابلة للتغيير لدورة الحياة.
- **Persistence والتزامن:** Persistence عبر PDO ومتطلبات MySQL-compatible، مع transactions وCAS وضمانات التزامن.
- **Resolution والإدارة:** Resolution وavailability واكتشاف Scopes وملخصات Scope وHistory التشغيلي وقراءات الإدارة مع pagination مشتركة.
- **الامتداد العام:** Custom Slug profiles بإصدارات ثابتة عبر Public registry path، مع ReservedSlugPolicy يملكه Host.

## المتطلبات

| المتطلب | القيمة |
|---|---|
| PHP | ^8.4 |
| قاعدة البيانات | PDO MySQL؛ وmysql:8.0.36 هو هدف قابلية إعادة الإنتاج في CI |
| Extensions | ext-intl, ext-mbstring, ext-pdo, ext-pdo_mysql |
| الحزم المباشرة | maatify/exceptions ^1.0, maatify/shared-common ^1.0, maatify/persistence ^1.1 |

تحتاج الـProfiles المدمجة إلى capabilities الخاصة بالـnormalization والـtransliteration التي يوفرها `ext-intl`، وتتحقق الحزمة منها أثناء التشغيل. لا يحتاج المستهلك إلى إصدار محدد من ICU أو Unicode.

## التثبيت

ثبّت Published Release Candidate بالأمر المحدد:

~~~bash
composer require maatify/php-slug:1.0.0-rc.1@RC
~~~

## الاستخدام السريع

المسار stateless لا يحتاج إلى قاعدة بيانات أو Host framework:

~~~php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$slug = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
// $slug->slug->value === 'hello-world'
~~~

في المسار persisted يحقن Host اتصال PDO وReservedSlugPolicyInterface وClockInterface في SlugEngineFactory::create(...). راجع docs/guides/USAGE_GUIDE.md للتدفق الكامل.

## Public Runtime API

يوفر الـRuntime العام مسارات فعلية لـ:

- canonicalization عبر SlugTextServiceInterface وgenerateFromSource وcanonicalizeClaim وcanonicalizeLookup.
- lifecycle ownership عبر SlugEngine وSlugLifecycleServiceInterface، بما في ذلك assignExact.
- consumer reads عبر checkAvailability وgetCurrent وresolve.
- management reads عبر SlugManagementQueryInterface، ومنها `searchScopes` و`getScopeOperationalSummary` و`searchHistory` في Runtime الحالي غير المنشور للـnext RC.

لا يحل هذا الملخص محل الفهرس والعقود الكاملة في SLUG_PACKAGE_REFERENCE.md.

## الأمثلة

- examples/canonicalization.php: مثال stateless للتوليد والـcanonicalization.
- examples/custom-profile.php: مثال تسجيل واستخدام custom profile عبر Public API.
- examples/persisted-lifecycle.php: مثال persisted لدورة MySQL باستخدام Compose lifecycle canonical.

يعمل المثال الأول دون Docker. أما الثاني فيحتاج إلى بيئة Integration التي يوفرها tools/ci/run-gate.sh.

## الوثائق

- docs/guides/USAGE_GUIDE.md — الاستخدام والتكامل للمستهلك.
- examples/ — أمثلة المستهلك القابلة للتشغيل.
- SLUG_PACKAGE_REFERENCE.md — العقد العام الحالي ومرجع Public API.
- CHANGELOG.md — تاريخ التغييرات وحالة النشر.
- SECURITY.md — سياسة الأمان ومسار البلاغات.
- CONTRIBUTING.md — إعداد التطوير وأوامر التحقق.
- [`README.md`](README.md) — النسخة الإنجليزية authoritative والـcanonical.

## Transactions والتزامن

في المسار persisted تملك الحزمة transaction عندما لا يملك Host outer transaction، وتستخدم savepoint عند المشاركة في outer transaction إذا كانت capability مدعومة. تعتمد exact claims على unique Registry constraint باعتبارها authority النهائية في race، وتستخدم lifecycle mutation CAS وidempotency وفق العقد. راجع SLUG_PACKAGE_REFERENCE.md للتفاصيل المعيارية.

## الأمان وحدود الثقة

يملك Host الاتصال وتهيئة PDO ووجود الكيان وrouting وHTTP وSEO وauthorization. لا تنشئ الحزمة اتصالات مخفية ولا تستخدم Host foreign keys أو joins. لبلاغات الثغرات، راجع SECURITY.md، ولا تنشر تفاصيل ثغرة خاصة في GitHub Issues.

## الاستثناءات وتمرير الأخطاء

تستخدم الإخفاقات الدلالية وإخفاقات النطاق المعرفة داخل الحزمة تسلسل `SlugExceptionInterface` و`SlugDomainExceptionInterface` الموضح في [Package Reference](SLUG_PACKAGE_REFERENCE.md#12-exception-contract). وقد تمرر أخطاء البنية التحتية الخارجية، ومنها أخطاء PDO خارج التحويلات الدلالية الموثقة، دون تغيير وفق العقد الحالي.

## حالة الجودة

تُصان الحزمة خلف بوابات CI والجودة المهيأة للمستودع. تُحفظ أدلة التنفيذ الحالية في سجل GitHub PR وCI. وتبقى Published Release Candidate في حالة Pre-Stable ولا تدعي الجاهزية للإصدار Stable.

## التطوير والاختبار

لإعداد المستودع والاختبارات وبوابات الأمثلة، راجع CONTRIBUTING.md. يحتاج المثال persisted إلى Compose lifecycle canonical وMySQL مؤقت؛ لا تستخدم External MySQL.

## الترخيص

مرخص بموجب MIT License.

## المؤلف

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
https://www.maatify.dev
