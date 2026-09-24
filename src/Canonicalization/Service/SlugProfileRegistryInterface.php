<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/** Resolves canonicalization profiles by their stable versioned key. */
interface SlugProfileRegistryInterface
{
    /** Adds a profile without replacing an existing profile with the same key. */
    public function register(SlugProfileInterface $profile): void;

    /** Returns the registered profile or fails when the key is unknown. */
    public function get(SlugProfileKey $key): SlugProfileInterface;

    /** Reports whether a profile key is registered. */
    public function has(SlugProfileKey $key): bool;
}
