# Maatify Slug — الترجمة العربية

> هذا الملف ترجمة عربية غير معيارية لملف [`README.md`](README.md).
> النسخة الإنجليزية هي الوثيقة authoritative وcanonical. عند وجود اختلاف، تكون النسخة الإنجليزية هي المرجع.

<div align="center">

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

محرك مستقل لدورة حياة Slug في حزم PHP، مع حدود واضحة بين نطاق Slug وHost وURL وHTTP وSEO.

</div>

---

## حالة النشر

تبقى الحزمة في حالة **Development / Unpublished** حتى يصبح المعرّف الأول المحدد للـRC، وهو `v1.0.0-rc.1`، قابلًا للحل والتثبيت خارجيًا عبر مصدر Composer المعتمد للتوزيع. لا يثبت branch أو commit أو CI أو Git tag أو GitHub Release وحده حالة Published. وإذا نُشرت هذه RC فستظل Pre-Stable ولا تنشئ Stable support line.

المرجع الحالي للعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). أدلة التحقق مرتبطة بالـSHA التي شُغّلت عليها؛ وترد الأدلة التاريخية أدناه، بينما تُحفظ حالة التكامل والتحقق النشطة في سجل GitHub PR وCI بدل تكرارها في ملف README هذا الموجّه للمستهلك.

## الميزات الأساسية

- **التوليد والـCanonicalization:** توليد وcanonicalization متسقان عبر Profiles مدمجة بإصدارات محددة.
- **الملكية ودورة الحياة:** Exact claiming وgenerated allocation وrelease وscope transition وatomic transfer وadoption.
- **Aliases وHistory:** Aliases نشطة ومتقاعدة مع History غير قابلة للتغيير لدورة الحياة.
- **Persistence والتزامن:** Persistence عبر PDO ومتطلبات MySQL-compatible، مع transactions وCAS وضمانات التزامن.
- **Resolution والإدارة:** Resolution وavailability وقراءات الإدارة مع pagination مشتركة.

## المتطلبات

| المتطلب | القيمة |
|---|---|
| PHP | ^8.4 |
| قاعدة البيانات | PDO MySQL؛ وmysql:8.0.36 هو هدف قابلية إعادة الإنتاج في CI |
| Extensions | ext-intl, ext-mbstring, ext-pdo, ext-pdo_mysql |
| الحزم المباشرة | maatify/exceptions ^1.0, maatify/shared-common ^1.0, maatify/persistence ^1.1 |

تحتاج الـProfiles المدمجة إلى capabilities الخاصة بالـnormalization والـtransliteration التي يوفرها `ext-intl`، وتتحقق الحزمة منها أثناء التشغيل. لا يحتاج المستهلك إلى إصدار محدد من ICU أو Unicode.

## التثبيت

عندما تصبح `v1.0.0-rc.1` قابلة للحل خارجيًا، ثبّت هذه النسخة السابقة للإصدار بالأمر المحدد:

~~~bash
composer require maatify/php-slug:1.0.0-rc.1@RC
~~~

وحتى ذلك الحين، استخدم development checkout والتحقق المحلي عبر [`CONTRIBUTING.md`](CONTRIBUTING.md).

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
- management reads عبر SlugManagementQueryInterface.

لا يحل هذا الملخص محل الفهرس والعقود الكاملة في SLUG_PACKAGE_REFERENCE.md.

## الأمثلة

- examples/canonicalization.php: مثال stateless للتوليد والـcanonicalization.
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

## حالة الجودة

يوفر GitHub Actions run #9 على SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b` دليلًا لذلك الـSHA فقط. نجح التحقق التاريخي `VG-001` على SHA `ff72d2e00a48eb5fbd55d81dea753a7f106187ef` عبر Actions run `35444700308`، ثم نجحت Direct Lead Fresh Full Acceptance Review قبل integration. تخص هذه الحقائق التاريخية الـSHAs المذكورة فقط ولا تؤهل أي commits لاحقة.

أدلة التحقق مرتبطة بالـSHA ولا تؤهل commits لاحقة. تُحفظ حالة التكامل والتحقق الحالية في GitHub PR وCI history ولا يتم تكرارها هنا. دليل CI وحده لا يثبت حالة Published أو Stable.

## التطوير والاختبار

لإعداد المستودع والاختبارات وبوابات الأمثلة، راجع CONTRIBUTING.md. يحتاج المثال persisted إلى Compose lifecycle canonical وMySQL مؤقت؛ لا تستخدم External MySQL.

## الترخيص

مرخص بموجب MIT License.

## المؤلف

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
https://www.maatify.dev
