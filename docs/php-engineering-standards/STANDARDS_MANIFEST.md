# Maatify/php-slug — سجل اعتماد المعايير

## بيانات الاعتماد

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Adoption Commit:** `2fc57f9320f8a7f7147fb20abbcfa311fdf40c28`
- **Adoption Date:** `2026-09-15`
- **Overall Resolution Status:** `VALID`
- **Resolution Status Priority:** `INVALID > OWNER DECISION REQUIRED > VALID`
- **Exception State:** `NONE`
- **Repository:** `Maatify/php-slug`
- **Composer Package:** `maatify/php-slug`
- **Namespace:** `Maatify\\Slug\\`

هذا السجل هو Local Resolver Record لنتيجة Selective Pinned Adoption. لا يضيف قواعد هندسية، ولا يدّعي إنشاء Blueprint أو تنفيذًا أو توافقًا معاييريا لعمل لم يُنجز بعد.

## حقائق الـArtifact ونطاق الحل

تمت مطابقة الحقائق الآتية مع حالة المستودع ومسودة النقاش المقبولة `docs/SLUG_LIBRARY_RC_CONCEPT_DISCUSSION.md`:

| الحقيقة | النتيجة |
|---|---|
| نوع الـArtifact | مكتبة PHP/Composer مستقلة جديدة قابلة لإعادة الاستخدام والتوزيع |
| هوية الحزمة | `maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| حد PHP المقصود | PHP `8.4` |
| حدود الاستضافة | Host-agnostic |
| ملكية المجال | الحزمة تملك Slug domain |
| Persistence | الحزمة تتضمن قدرات lifecycle وpersistence مملوكة لها |
| نوع المستودع | ليس Host-specific module ولا Slim module ولا application repository |
| قابلية التثبيت | الحزمة مصممة لتكون مستقلة وقابلة للتثبيت عبر Composer |
| نطاق الحوكمة | قواعد الحوكمة تنطبق على جذر المستودع `/` |
| حالة التحضير | عند SHA الأساس لا تزال الحزمة في RC1 Preparation؛ عدم وجود `composer.json` أو source implementation في هذه المرحلة مقصود، ولا يغيّر نوع الـArtifact المستهدف |

## Profile Activations

| Profile ID | Profile Version | Scope | Extends | Resolution Status |
|---|---:|---|---|---|
| `composer-package` | `1.0.0` | `/` | `None` | `VALID` |
| `repository-governance` | `1.0.0` | `/` | `None` | `VALID` |

تُسجل `Resolution Status` لكل Profile Activation/Scope بصورة مستقلة. وتكون `Overall Resolution Status` هنا `VALID` بعد ثبوت عدم وجود أي Activation/Scope بحالة `INVALID` أو `OWNER DECISION REQUIRED`، وعدم وجود قرار Adoption غير محسوم؛ وتُطبق أولوية التجميع: `INVALID > OWNER DECISION REQUIRED > VALID`.

### Profiles الموروثة

لا توجد Profiles موروثة. كلا الـProfiles المفعّلين يعلن `Extends: None`.

## Structural / Transitive Resolution

اكتمل الحل البنيوي لكل Activation/Scope:

- تم التحقق من وجود كل Profile مفعّل.
- لا توجد inheritance cycles.
- لا توجد Profiles موروثة مطلوبة.
- تم الاحتفاظ بكل مراجع `Required Standards` السبعة كمرشحين قبل تقييم الانطباق.
- لا توجد `Explicit Additional Standards`.

### Candidate Standard References

| المصدر | Standard ID | المسار upstream | النتيجة البنيوية |
|---|---|---|---|
| `composer-package` | `std-package-building` | `standards/packages/PACKAGE_BUILDING_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-composer-package` | `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-ci-workflow` | `standards/packages/CI_WORKFLOW_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-library-presentation` | `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-testing` | `standards/testing/TESTING_STANDARD.md` | موجود وصحيح بنيويًا |
| `repository-governance` | `std-ai-collaboration-workflow` | `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | موجود وصحيح بنيويًا |
| `repository-governance` | `std-github-phase-stack-workflow` | `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | موجود وصحيح بنيويًا |

## Pinned Adoption Control Set

جميع الملفات الآتية نسخ مثبتة من Adoption Commit نفسه، مع الحفاظ على البنية النسبية:

- `docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md`
- `docs/php-engineering-standards/standards/profiles/COMPOSER_PACKAGE_PROFILE.md`
- `docs/php-engineering-standards/standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md`

لا توجد ملفات Profile غير مفعّلة أو غير موروثة ضمن Control Set.

## Final Resolved Applicable Standards Set

بعد تطبيق canonical Applicability الخاصة بكل Standard على Scope `/` وحقائق الـArtifact، تكون المجموعة النهائية كما يلي:

| Standard ID | Standard Version | المسار المحلي | Canonical Applicability |
|---|---:|---|---|
| `std-package-building` | `1.3.0` | `docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md` | منطبق على مكتبة PHP/Composer مستقلة؛ Persistence rules مشروطة ومفعّلة لأن الحزمة تملك Persistence |
| `std-composer-package` | `1.2.0` | `docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md` | منطبق على مكتبة PHP/Composer مستقلة قابلة لإعادة الاستخدام |
| `std-ci-workflow` | `1.1.0` | `docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md` | منطبق على بنية CI الخاصة بحزمة Composer مستقلة |
| `std-library-presentation` | `1.0.1` | `docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | منطبق على مكتبة PHP/Composer مستقلة |
| `std-testing` | `1.1.0` | `docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md` | منطبق كمعيار الاختبار للمستودع والحزمة |
| `std-ai-collaboration-workflow` | `6.0.0` | `docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | منطبق على Scope الحوكمة المفعّل في `/` |
| `std-github-phase-stack-workflow` | `2.2.0` | `docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | منطبق على دورة RC1 الحالية التي تتبع Phase Stack |

لم يُستبعد أي Candidate Standard؛ لذلك لا توجد Standard مفقودة من المجموعة النهائية ولا Standard غير منطبقة مسجلة على أنها Applicable.

## Explicit Additional Standards

`None`.

## Explicit Exceptions / Overrides

`None`.

الملفان `standards/governance/STANDARD_VERSIONING_POLICY_AR.md` و`standards/modules/MODULE_BUILDING_STANDARD.md` ليسا جزءًا من Control Set أو Applicable Set؛ ينص Adoption Standard على عدم نسخ الملفات المرجعية أو المعايير غير المنطبقة لمجرد أن ملفًا مثبتًا يشير إليها. هذا استبعاد تعاقدي وليس Exception أو Override. جميع الروابط النسبية بين الملفات المثبتة والمنطبقة محفوظة وصالحة، أما هذان المرجعان فخارج نطاق هذه الحزمة.

## سلامة الروابط

- حُفظت البنية النسبية المحلية تحت `docs/php-engineering-standards/standards/`.
- الروابط بين Adoption Standard وProfiles والمعايير المنطبقة تحافظ على المسارات النسبية المطلوبة.
- لم تُنسخ `docs/audits/` أو `docs/decisions/` أو شجرة `standards/` كاملة.

## حدود هذا الاعتماد

- هذا السجل يثبت نتيجة حل المعايير فقط.
- لم يُنشأ Blueprint أو schema أو API أو roadmap أو Work Unit implementation plan.
- لم تُضف PHP أو SQL أو migrations أو tests أو CI أو runtime configuration.
- تظل هذه الـAdoption بحاجة إلى independent review قبل دمج Draft PR.
