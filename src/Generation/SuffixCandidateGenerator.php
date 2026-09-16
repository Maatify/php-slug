<?php

declare(strict_types=1);

namespace Maatify\Slug\Generation;

use Maatify\Slug\Canonicalization\CanonicalSlugRules;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Validation\SlugInputValidator;

final class SuffixCandidateGenerator
{
    /** @return list<string> */
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

    private function __construct()
    {
    }
}
