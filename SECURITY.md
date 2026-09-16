# Security Policy

[![Maatify Slug](https://img.shields.io/badge/Maatify-Slug-blue?style=for-the-badge)](https://github.com/Maatify/php-slug)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## حالة الدعم الحالية

المستودع في RC1 Preparation Closure، ولا توجد حاليًا نسخة Stable منشورة أو خط إصدار Stable مدعوم. لا يمثل هذا الفرع أو Draft PR نسخة منشورة أو Release Candidate قابلًا للاستهلاك الخارجي، ولا يُفهم من العقد الموثق هنا وجود دعم أمني لـRuntime غير موجود بعد.

## Supported Versions

| Version | Supported |
|---|---|
| No published release | No |

سيُحدَّث هذا الجدول فقط عندما تتغير حالة النشر وسياسة الدعم الفعلية، وليس عند إنشاء branch أو tag غير منشور أو نجاح CI.

## نطاق سياسة الأمان

يشمل نطاق الحزمة عند وجود Runtime: Slug profiles وinput validation وownership/lifecycle وHistory وPDO MySQL persistence وtransactions وconcurrency وpublic result/exception contracts.

يبقى خارج نطاق الحزمة: Host entity persistence/existence، authentication وauthorization، routing وURL transport، HTTP status/redirect policy، SEO، Framework integrations، وHost infrastructure. يجب إرسال مشكلة تخص هذه المجالات إلى مالك التطبيق أو الـadapter المسؤول عنها.

## الإبلاغ الخاص

لا تستخدم GitHub Issues لنشر تفاصيل ثغرة لم تُعالج. أرسل بلاغًا خاصًا إلى `support@maatify.com`، مع:

- وصف واضح للمشكلة وتأثيرها.
- خطوات إعادة الإنتاج أو إثبات المفهوم الآمن.
- النسخة أو commit المتأثرة وبيئة التشغيل.
- أي تخفيف مؤقت معروف، مع حذف الأسرار والبيانات الشخصية.

لا يحدد هذا المستند مدة استجابة أو إصلاح؛ تعتمد المعالجة على تحقق البلاغ ونطاقه وحالة Runtime المنشورة وقت وصوله.

## حدود الادعاء الحالية

لا توجد في الحالة الحالية Composer metadata أو Runtime أو Schema أو Tests أو CI، ولذلك لا تدعي هذه السياسة security audit أو production deployment أو release readiness. أي ادعاء لاحق عن دعم إصدار أو إصلاح أمني يجب أن يطابق نسخة منشورة وسياسة الدعم الفعلية.

للعقد الفني وحدود الثقة، راجع [Package Reference](SLUG_PACKAGE_REFERENCE.md).
