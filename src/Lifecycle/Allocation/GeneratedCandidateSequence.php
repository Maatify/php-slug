<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Allocation;

use Maatify\Slug\Lifecycle\Allocation\SuffixCandidateGenerator;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Contract\SlugProfileInterface;

/**
 * Internal bounded candidate preparation. WU-02 remains the owner of the
 * suffix/truncation algorithm; this class only applies it to the selected
 * Profile and returns validated Slug values in order.
 */
final class GeneratedCandidateSequence
{
    /** @return list<Slug> */
    public function fromSource(SlugProfileInterface $profile, string $source): array
    {
        $base = $profile->generateFromSource($source)->slug;

        return array_map(
            static fn(string $candidate): Slug => Slug::fromProfile($profile, $candidate),
            SuffixCandidateGenerator::prepare($base->value),
        );
    }
}
