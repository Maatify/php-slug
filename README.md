<div align="center">

# Maatify Slug

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

[![RC1 Preparation](https://img.shields.io/badge/Status-RC1%20Preparation-orange.svg)](#حالة-الحزمة)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](SLUG_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)

توثيق عقد RC1 لمحرك دورة حياة Slug مستقل لحزم PHP، مع فصل واضح بين Slug domain وHost وURL وHTTP وSEO.

Composer package: `maatify/php-slug`

</div>

---

## حالة الحزمة

المستودع في **RC1 Preparation Closure**. لا توجد حاليًا `composer.json` أو `src/` أو `tests/` أو `schema/` أو CI، ولا توجد نسخة منشورة قابلة للتثبيت. لذلك لا يمثل هذا الفرع أو Draft PR Release Candidate منشورًا أو Stable release، ولا يوجد أمر تثبيت عامل في الحالة الحالية.

المرجع الكامل للحالة والعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## ماذا تسجل RC1

- Slug generation وcanonicalization وlookup عبر Profiles versioned.
- scoped ownership وexact claiming وgenerated allocation بحدود معلنة.
- current وhistorical canonical وactive/retired aliases وimmutable History.
- lifecycle وrelease وpurge وscope transition وatomic transfer وlegacy adoption.
- resolution وavailability وmanagement queries مع pagination مشتركة.
- PDO MySQL persistence وtransactions وCAS وconcurrency وidempotent result snapshots.

هذه نقاط العقد المقبول للتنفيذ اللاحق وليست ادعاءً بأن Runtime موجود أو أن gates نجحت.

## المتطلبات المسجلة للعقد

| المتطلب | القيمة |
|---|---|
| PHP | `^8.4` |
| Profiles | ICU major `74` وUnicode data `15.1` |
| Database | PDO MySQL وMySQL `8.0.36` فقط |
| Extensions | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Direct packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/persistence ^1.1` |

راجع [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) للتفاصيل والحدود؛ لا تعني هذه القائمة توفر المتطلبات في الفرع الحالي.

## التثبيت والوصول

لا توجد نسخة منشورة أو `composer.json` في الحالة الحالية، ولذلك لا يُقدَّم أمر `composer require` ولا مثال تثبيت يوحي بوجود artifact قابل للتنزيل. للوصول إلى العقد الحالي، ابدأ من [Package Reference](SLUG_PACKAGE_REFERENCE.md)، ثم راجع [Blueprint](docs/SLUG_LIBRARY_RC1_BLUEPRINT.md) و[Implementation Plan](docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md).

## Public API

العقود العامة المقفلة تشمل `SlugTextServiceInterface` و`SlugProfileInterface` و`SlugProfileRegistryInterface` و`ReservedSlugPolicyInterface` و`SlugScopeRegistryInterface` و`SlugLifecycleServiceInterface` و`SlugQueryServiceInterface` و`SlugManagementQueryInterface`، مع `SlugProfileRegistryFactory` و`SlugTextServiceFactory` و`SlugEngineFactory` لمساري الإنشاء stateless وpersisted.

كل lifecycle method يستقبل Command محددًا، وكل query filter يستقبل Criteria. لا تكشف الحزمة repositories أو SQL أو lock coordinators كـpublic API، ولا تنشئ pagination types محلية بدل `maatify/persistence`.

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

لم تُنشأ بعد Runtime أو Schema أو Tests أو CI أو Composer metadata في الحالة الحالية. لذلك لا توجد نتيجة اختبارات أو PHPStan أو Consumer Verification Harness أو security audit يمكن نسبها إلى هذا الفرع.

## التطوير والاختبار

تفاصيل Work Units وFull Applicable Verification Set موجودة في [Implementation Plan](docs/SLUG_LIBRARY_RC1_IMPLEMENTATION_PLAN.md). هذه المهمة لا تنشئ implementation ولا تشغّل Runtime test suite؛ أي gate غير مشغلة لا تُعرض كأنها ناجحة.

## License

لا يوجد ملف `LICENSE` مسجل في الحالة الحالية، لذلك لا تنسب هذه الوثائق ترخيصًا غير مثبت.

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
