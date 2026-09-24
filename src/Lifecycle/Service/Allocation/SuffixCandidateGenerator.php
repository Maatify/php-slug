<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Allocation;

use Maatify\Slug\Canonicalization\Service\CanonicalSlugRules;
use Maatify\Slug\Lifecycle\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Canonicalization\Service\SlugInputValidator;

/** Generates bounded numeric suffix candidates for generated slug allocation. */
final class SuffixCandidateGenerator
{
    /**
     * Returns the base and bounded `-2` through `-1000` candidates.
     *
     * @return list<string>
     */
    public static function prepare(string $base): array
    {
        CanonicalSlugRules::assertUnicode($base, 'base');

        $candidates = [$base];
        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            $suffixText = '-' . $suffix;
            $baseLimit = CanonicalSlugRules::MAX_CODE_POINTS - SlugInputValidator::codePointLength($suffixText);
            $prefix = rtrim(mb_substr($base, 0, $baseLimit, 'UTF-8'), '-');
            if ($prefix === '') {
                throw new SlugAllocationExhaustedException('No valid bounded suffix candidate remains.');
            }
            $candidates[] = $prefix . $suffixText;
        }

        return $candidates;
    }

    private function __construct() {}
}
