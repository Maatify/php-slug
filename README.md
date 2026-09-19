<div align="center">

# Maatify Slug

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![Status](https://img.shields.io/badge/Status-Development-orange.svg)](#حالة-الحزمة-والنشر)
[![PHP](https://img.shields.io/badge/PHP-^8.4-777bb4.svg?logo=php&logoColor=white)](#المتطلبات)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Max-brightgreen.svg)](#quality-status)

[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Usage-Guide-blue.svg)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Examples-View-blue.svg)](examples/)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](SLUG_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Contributing-Guide-blue.svg)](CONTRIBUTING.md)

محرك مستقل لدورة حياة Slug في حزم PHP، مع فصل واضح بين Slug domain وHost وURL وHTTP وSEO.

</div>

---

## حالة الحزمة والنشر

الحزمة في حالة **Development / Unpublished**. لا يوجد إصدار Stable أو Release Candidate منشور عبر قناة توزيع عامة، ولا يوجد حاليًا أمر تثبيت public صالح من registry.

المرجع الحالي للعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). وتبقى حالة التحقق النهائي منفصلة: توجد أدلة نجاح تاريخية محددة أدناه، لكن `VG-001` ومراجعة القبول النهائية ما زالا مطلوبين بعد إغلاق remediation.

## الميزات الأساسية

- **Slug Generation & Canonicalization:** توليد وcanonicalization متسقان عبر Profiles مدمجة بإصدارات محددة.
- **Ownership & Lifecycle:** exact claiming وgenerated allocation وrelease وscope transition وatomic transfer وadoption.
- **Aliases & History:** aliases نشطة أو متقاعدة وHistory دائم للتغيرات.
- **Persistence & Concurrency:** Persistence عبر PDO MySQL-compatible مع transactions وCAS وضمانات التزامن.
- **Resolution & Management:** resolution وavailability وmanagement reads مع pagination مشتركة.

## المتطلبات

| المتطلب | القيمة |
|---|---|
| PHP | `^8.4` |
| Database | PDO MySQL؛ `mysql:8.0.36` هو CI reproducibility target |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Direct packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |

## Installation

الحزمة غير منشورة؛ لذلك لا يوجد public distribution installation command صالح حاليًا ولا ينبغي عرض `composer require` كأمر قابل للاستخدام. لإعداد checkout التطويري وتشغيل التحقق المحلي، راجع [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Quick Usage

المسار stateless لا يحتاج إلى Database أو Host framework:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$slug = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
// $slug->slug->value === 'hello-world'
```

للمسار persisted، يحقن Host اتصال `PDO` وسياسة `ReservedSlugPolicyInterface` و`ClockInterface` في `SlugEngineFactory::create(...)`. راجع [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) للتدفق الكامل.

## Public Runtime API

يوفر الـRuntime العام مسارات فعلية لـ:

- canonicalization عبر `SlugTextServiceInterface` و`generateFromSource` و`canonicalizeClaim` و`canonicalizeLookup`.
- lifecycle ownership عبر `SlugEngine` و`SlugLifecycleServiceInterface`، ومنها `assignExact`.
- consumer reads عبر `checkAvailability` و`getCurrent` و`resolve`.
- management reads عبر `SlugManagementQueryInterface`.

هذا overview لا يستبدل inventory والعقود الكاملة في [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## Examples

- [`examples/canonicalization.php`](examples/canonicalization.php): مثال stateless للتوليد والـcanonicalization.
- [`examples/persisted-lifecycle.php`](examples/persisted-lifecycle.php): مثال persisted يستخدم MySQL عبر دورة Compose canonical.

الأول يعمل دون Docker، والثاني يحتاج إلى بيئة Integration التي يشغلها [`tools/ci/run-gate.sh`](tools/ci/run-gate.sh).

## Documentation

- [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) — طريقة الاستخدام والتكامل.
- [`examples/`](examples/) — أمثلة المستهلك القابلة للتشغيل.
- [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) — العقد العام الحالي ومرجع Public API.
- [`CHANGELOG.md`](CHANGELOG.md) — تاريخ التغييرات وحالة النشر.
- [`SECURITY.md`](SECURITY.md) — سياسة الأمان ومسار البلاغات.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — إعداد التطوير وأوامر التحقق.

## Transactions and Concurrency

في المسار persisted، تملك الحزمة transaction عندما لا يملك Host outer transaction، وتستخدم savepoint عند المشاركة في outer transaction بحسب capability. تعتمد exact claims على unique Registry constraint باعتبارها السلطة النهائية في race، وتستخدم lifecycle mutations CAS وidempotency وفق العقد. التفاصيل المعيارية في [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## حدود الأمان والثقة

Host يملك الاتصال وتهيئة PDO ووجود الكيان وrouting وHTTP وSEO وauthorization. الحزمة لا تنشئ اتصالات مخفية ولا تستخدم Host FKs أو JOINs. لبلاغات الثغرات، راجع [Security Policy](SECURITY.md) ولا تستخدم GitHub Issues للإفصاح عن تفاصيل ثغرة خاصة.

## Quality Status

توجد أدلة نجاح تاريخية لـGitHub Actions run #9 على SHA `7d4d67e624a3e79ddf5ab471fbf4acaaea5eeb7b`. هذه الأدلة تخص ذلك الـSHA فقط ولا تؤهل current remediation HEAD. ما زال `VG-001` ومراجعة `Fresh Full Acceptance Review` مطلوبين، ولا يوجد ادعاء release-readiness حاليًا.

## التطوير والاختبار

لإعداد المستودع وتشغيل الاختبارات وبوابات الأمثلة، راجع [`CONTRIBUTING.md`](CONTRIBUTING.md). المثال persisted يحتاج إلى canonical Compose lifecycle وMySQL disposable؛ لا تستخدم External MySQL.

## License

مرخص بموجب ترخيص [MIT](LICENSE).

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
