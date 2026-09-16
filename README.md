<div align="center">

# Maatify Slug

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![PHP](https://img.shields.io/badge/PHP-^8.4-777bb4.svg?logo=php&logoColor=white)](#المتطلبات)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Max-brightgreen.svg)](#quality-status)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](SLUG_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)

محرك دورة حياة Slug مستقل لحزم PHP، مع فصل واضح بين Slug domain وHost وURL وHTTP وSEO.

Composer package: `maatify/php-slug`

</div>

---

## حالة الحزمة والنشر

الحزمة **غير منشورة حاليًا** ولا يتوفر لها إصدار Stable أو Release Candidate منشور عبر Packagist. هذا المستودع يحتوي على التنفيذ الفعلي للحزمة، ولكن لا يمكن استخدام `composer require maatify/php-slug` من مصادر التوزيع العامة بعد.

المرجع الكامل للحالة والعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## الميزات الأساسية

- **Slug Generation & Canonicalization:** توليد متوافق ومتسق يعتمد على Profiles مدمجة وإصدارات محددة (ICU/Unicode).
- **Ownership & Lifecycle:** إدارة دورة حياة كاملة (exact claiming, generated allocation, release, purge, scope transition, atomic transfer, legacy adoption).
- **Aliases & History:** سجل دائم وimmutable للتغيرات، وإدارة للروابط البديلة (active/retired aliases).
- **Persistence & Concurrency:** دعم PDO MySQL، ضمانات المعاملات (transactions)، CAS، ومعالجة التزامن.
- **Resolution & Management:** استعلامات الإدارة والبحث مع دعم التصفية المتقدمة وتقسيم الصفحات المشترك (pagination).

## المتطلبات

| المتطلب | القيمة |
|---|---|
| PHP | `^8.4` |
| Database | PDO MySQL (توافق MySQL-compatible semantics؛ `mysql:8.0.36` هو CI reproducibility target) |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Direct packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |

## الوصول

للوصول إلى الكود المصدري والحزمة الحالية، يمكنك استنساخ المستودع. اقرأ [Package Reference](SLUG_PACKAGE_REFERENCE.md) للتعرف على واجهات برمجة التطبيقات والمفاهيم.

## Public API والاستخدام (أمثلة)

العقود العامة تشمل `SlugTextServiceInterface` و`SlugProfileInterface` و`SlugProfileRegistryInterface` و`ReservedSlugPolicyInterface` و`SlugScopeRegistryInterface` و`SlugLifecycleServiceInterface` و`SlugQueryServiceInterface` و`SlugManagementQueryInterface`. لا تكشف الحزمة repositories أو SQL أو lock coordinators كـpublic API، ولا تنشئ pagination types محلية بدل `maatify/persistence`. مسار Schema موجود في `schema/`.

**مثال على إنشاء واستخدام Stateless Factory:**
```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$textService = SlugTextServiceFactory::createDefault($profiles);
$slug = $textService->generateSlug($profiles->get('standard'), 'My New Article!');
```

**مثال على إنشاء Persisted Engine عبر `SlugEngineFactory`:**
```php
$engine = SlugEngineFactory::create(
    $pdo,                  // Host PDO instance
    $profiles,             // SlugProfileRegistryInterface
    $reservedPolicy,       // ReservedSlugPolicyInterface
    $clock                 // ClockInterface (UTC)
);
$lifecycle = $engine->getLifecycle();
$query = $engine->getQuery();
```

## المعاملات والتزامن (Transactions & Concurrency)

- **Transactions:** إذا لم يوفر الـ Host معاملة (outer transaction)، تقوم الحزمة بإدارة المعاملة لضمان الـ atomic persistence الخاص بها. تستخدم الحزمة `savepoint` لعمليات الـ nested participation. في حال الفشل، تعيد الحزمة الـ Throwable الأصلي بعد الـ rollback دون ابتلاعه.
- **Concurrency & Idempotency:** يوفر الـ Engine ضمانات Concurrency باستخدام MySQL constraints كـ claim authority النهائية، بالإضافة إلى Compare-and-Swap (CAS) لحماية الـ lifecycle state. العمليات توفر Idempotency عن طريق تسجيل Result Snapshots آمنة يمكن إعادة تشغيلها بأمان تام. لمزيد من التفاصيل، انظر [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## حدود الأمان والثقة

Host يملك الاتصال وتهيئة PDO ووجود الكيان وrouting وHTTP وSEO وauthorization. الحزمة لا تنشئ اتصالات مخفية، ولا تقرأ Host `.env`، ولا تستخدم Host FKs أو JOINs. Profile validation ترفض invalid UTF-8 وNUL وcontrol/format code points وفواصل المسار قبل التحويلات lossy، ويظل `checkAvailability` فحصًا advisory لا ضمان claim.

لبلاغات الثغرات، راجع [Security Policy](SECURITY.md). لا تستخدم GitHub Issues للإفصاح العام عن تفاصيل ثغرة خاصة.

## التوثيق

- [Package Reference](SLUG_PACKAGE_REFERENCE.md) — المصدر الجذري للعقد والحالة.
- [RC1 Blueprint](docs/SLUG_LIBRARY_RC1_BLUEPRINT.md) — supporting architecture contract.
- [RC1 Implementation Plan](docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md) — execution and evidence gates.
- [Changelog](CHANGELOG.md) — التغييرات التوثيقية وحالة الإصدارات.
- [Security Policy](SECURITY.md) — الدعم ومسار البلاغات.

## Quality Status

نجحت الحزمة في تجاوز بوابات التحقق (Quality/Compatibility Gates) المحددة في CI، والتي تشمل الـRuntime، Tests (Unit & Integration)، Strict types، وPHPStan Max level، إلى جانب Consumer Verification Harness.
(ملاحظة: اجتياز الـ CI gates هو إثبات للتحقق الفني، لكنه لا يعتبر وعدًا بالدعم العام قبل النشر الرسمي).

## التطوير والاختبار

للعمل على المستودع أو تشغيل الاختبارات، راجع [`CONTRIBUTING.md`](CONTRIBUTING.md).
الاختبارات تتطلب بيئة قاعدة بيانات MySQL للتحقق من Integration.

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
