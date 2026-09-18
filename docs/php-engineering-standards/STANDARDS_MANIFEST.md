# Maatify/php-slug — سجل اعتماد المعايير

## بيانات الاعتماد

- **Upstream Repository:** `Maatify/php-engineering-standards`
- **Adoption Commit:** `4e268089d0aceedbc837d98f28da8b204d39dd7f`
- **Adoption Date:** `2026-09-19`
- **Overall Resolution Status:** `VALID`
- **Resolution Status Priority:** `INVALID > OWNER DECISION REQUIRED > VALID`
- **Exception State:** `NONE`
- **Repository:** `Maatify/php-slug`
- **Composer Package:** `maatify/php-slug`
- **Namespace:** `Maatify\\Slug\\`

هذا السجل هو Local Resolver Record لنتيجة Selective Pinned Adoption المكتملة من exact upstream commit المسجل أعلاه. لا يضيف قواعد هندسية، ولا يغيّر نتيجة الاعتماد، ولا يثبت وحده نجاح الاختبارات أو النشر.

## حقائق الـArtifact ونطاق الحل

تمت إعادة تقييم الحقائق من حالة `php-slug` الحالية بعد اكتمال Runtime artifacts، لا من حالة Preparation السابقة:

| الحقيقة | النتيجة |
|---|---|
| نوع الـArtifact | مكتبة PHP/Composer مستقلة قابلة لإعادة الاستخدام والتوزيع؛ `composer.json` يعلن `type: library` وPSR-4 production autoload |
| هوية الحزمة | `maatify/php-slug` |
| Namespace | `Maatify\\Slug\\` |
| حد PHP المقصود | PHP `^8.4` |
| Extensions التشغيلية | `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| حالة Runtime | توجد ملفات `composer.json` و`src/` و`tests/` و`schema/mysql/` و`.github/workflows/ci.yml` في الـcheckout الحالي |
| Persistence | الحزمة تملك SQL persistence مملوكة لها، مع طبقة PDO تحت `src/Persistence/PDO/` ومخطط package-owned تحت `schema/mysql/` |
| قاعدة البيانات | مسار MySQL-compatible عبر `pdo_mysql`؛ الجداول والعلاقات package-local ولا توجد Host FKs أو Host table joins |
| حدود الاستضافة | Host-agnostic؛ Host يهيئ اتصال PDO ويحقنه، والحزمة لا تنشئ اتصالًا مخفيًا |
| نطاق الحوكمة | قواعد الحوكمة مفعلة على جذر المستودع `/` |

## Profile Activations

| Profile ID | Profile Version | Scope | Extends | Resolution Status |
|---|---:|---|---|---|
| `composer-package` | `2.0.0` | `/` | `None` | `VALID` |
| `repository-governance` | `2.0.0` | `/` | `None` | `VALID` |

سُجلت نتيجة كل Profile Activation/Scope بصورة مستقلة. وتكون `Overall Resolution Status` هنا `VALID` بعد ثبوت سلامة الحل البنيوي، وانطباق كل Candidate Standard، والتحقق من Frozen Profile Version Baseline، وعدم وجود قرار Adoption معلق أو Exception مطلوبة.

### Profiles الموروثة

لا توجد Profiles موروثة. كلا الـProfiles المفعّلين يعلن `Extends: None`.

## Structural / Transitive Resolution

اكتمل الحل البنيوي لكل Activation/Scope قبل تقييم canonical applicability:

- تم التحقق من وجود كل Profile مفعّل في exact Target Adoption Commit.
- لا توجد inheritance cycles.
- لا توجد Profiles موروثة مطلوبة؛ كلا الـProfiles يعلن `Extends: None`.
- تم جمع تسعة مراجع `Required Standards` كمرشحين قبل أي تصفية، مع إزالة التكرار عند تكوين المجموعة النهائية.
- لا توجد `Explicit Additional Standards`.

### Candidate Standard References

| المصدر | Standard ID | المسار upstream | النتيجة البنيوية |
|---|---|---|---|
| `composer-package` | `std-package-building` | `standards/packages/PACKAGE_BUILDING_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-composer-package` | `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-ci-workflow` | `standards/packages/CI_WORKFLOW_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-library-presentation` | `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-testing` | `standards/testing/TESTING_STANDARD.md` | موجود وصحيح بنيويًا |
| `composer-package` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | موجود وصحيح بنيويًا |
| `repository-governance` | `std-ai-collaboration-workflow` | `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | موجود وصحيح بنيويًا |
| `repository-governance` | `std-github-phase-stack-workflow` | `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | موجود وصحيح بنيويًا |
| `repository-governance` | `std-documentation-lifecycle` | `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | موجود وصحيح بنيويًا؛ مكرر مرجعيًا ولا يضيف Standard جديدة |

## Pinned Adoption Control Set

جميع الملفات الآتية نسخ مثبتة من Adoption Commit `4e268089d0aceedbc837d98f28da8b204d39dd7f` نفسه، مع الحفاظ على البنية النسبية:

- `docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md` — Standard Version `3.0.0`
- `docs/php-engineering-standards/standards/profiles/COMPOSER_PACKAGE_PROFILE.md` — Profile Version `2.0.0`
- `docs/php-engineering-standards/standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md` — Profile Version `2.0.0`

لا توجد ملفات Profile غير مفعّلة أو غير موروثة ضمن Control Set.

## Final Resolved Applicable Standards Set

بعد تطبيق canonical Applicability الخاصة بكل Standard على Scope `/` وحقائق الـArtifact الحالية، تكون المجموعة النهائية كما يلي:

| Standard ID | Standard Version | المسار المحلي | Canonical Applicability |
|---|---:|---|---|
| `std-package-building` | `2.0.0` | `docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md` | منطبق على مكتبة PHP/Composer مستقلة؛ وقواعد SQL persistence المشروطة منطبقة لأن الحزمة تملك SQL persistence فعلية مع direct PDO وschema package-owned |
| `std-composer-package` | `3.0.0` | `docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md` | منطبق على `composer.json` الخاص بمكتبة PHP/Composer مستقلة قابلة لإعادة الاستخدام |
| `std-ci-workflow` | `2.0.0` | `docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md` | منطبق على بنية التحقق وCI الخاصة بحزمة Composer مستقلة |
| `std-library-presentation` | `2.0.0` | `docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | منطبق على مستودع مكتبة PHP/Composer المستقلة وعرضها release-facing |
| `std-testing` | `1.1.0` | `docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md` | منطبق على الاختبارات وحماية السلوك للمستودع والحزمة ذات Runtime وPersistence |
| `std-documentation-lifecycle` | `1.0.0` | `docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | منطبق على Standalone Package وعلى نطاق Repository Governance الذي يملك وثائق الحالة الحالية وVerification evidence وسجل الاعتماد |
| `std-ai-collaboration-workflow` | `7.0.0` | `docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | منطبق على Scope الحوكمة المفعّل في `/` |
| `std-github-phase-stack-workflow` | `3.0.0` | `docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | منطبق على دورة التنفيذ الحالية للمستودع التي تتبع Phase Stack |

لم يُستبعد أي Candidate Standard؛ لذلك لا توجد Standard غير منطبقة مسجلة ضمن Applicable Set، ولا توجد فجوة applicability غير محسومة.

## Explicit Additional Standards

`None`.

## Explicit Exceptions / Overrides

`None`.

الملفات `standards/governance/STANDARD_VERSIONING_POLICY_AR.md` و`standards/modules/` و`standards/projects/` ليست جزءًا من Control Set أو Applicable Set؛ عدم نسخها التزام بحدود Selective Adoption وليس Exception أو Override. كما لم تُنسخ `docs/audits/` أو `docs/decisions/` أو شجرة `standards/` كاملة.

## سلامة الروابط

- حُفظت البنية النسبية المحلية تحت `docs/php-engineering-standards/standards/`.
- الروابط النسبية بين ملفات Control Set وApplicable Set المختارة، وكذلك روابط Profiles إلى المرشحين، تشير إلى المسارات الصحيحة في النسخة المحلية.
- الإحالات النسبية إلى `STANDARD_VERSIONING_POLICY_AR.md` و`MODULE_BUILDING_STANDARD.md` و`PROJECT_DOCUMENTATION_STANDARD_AR.md` مراجع upstream مقصودة لمواد غير منسوخة؛ لم تُحوّل إلى ملفات محلية إضافية حتى لا يتوسع Adoption خارج resolution.
- كل ملف منسوخ من upstream في Control Set أو Applicable Set يطابق محتوى exact Adoption Commit المسجل أعلاه.
- لا توجد ملفات زائدة ضمن شجرة Adoption المحلية خارج Manifest والـPinned Adoption Files المسجلة.

## Resolution Status

الحل البنيوي والـCanonical Applicability مكتملان لكل Activation/Scope، والتحقق من Frozen Profile Version Baseline مكتمل، وجميع النتائج `VALID`. لا توجد `INVALID` أو `OWNER DECISION REQUIRED`، و`Exception State = NONE`.

## حدود هذا الاعتماد

- هذا السجل يثبت نتيجة حل المعايير ومجموعة الملفات المثبتة فقط.
- وجود Runtime artifacts في الـcheckout الحالي لا يحول هذا السجل إلى تقرير نجاح لاختبارات PHP أو PHPStan أو CI أو MySQL؛ تلك النتائج تحتاج أدلة تشغيل مستقلة.
- لا يضيف هذا الاعتماد Runtime أو Tests أو CI أو Blueprint أو Implementation Plan أو package documentation إلى نطاق التغيير.
