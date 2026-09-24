<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Allocation;

use Maatify\Slug\Lifecycle\Service\Allocation\SuffixCandidateGenerator;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;

/**
 * Internal bounded candidate preparation. WU-02 remains the owner of the
 * suffix/truncation algorithm; this class only applies it to the selected
 * Profile and returns validated Slug values in order.
 *
 * Applies the package's bounded suffix algorithm to profile-generated slugs.
 */
final class GeneratedCandidateSequence
{
    /**
     * Returns validated candidates in allocation order, beginning with the generated base.
     *
     * @return list<Slug>
     */
    public function fromSource(SlugProfileInterface $profile, string $source): array
    {
        $base = $profile->generateFromSource($source)->slug;

        return array_map(
            static fn(string $candidate): Slug => Slug::fromProfile($profile, $candidate),
            SuffixCandidateGenerator::prepare($base->value),
        );
    }
}
