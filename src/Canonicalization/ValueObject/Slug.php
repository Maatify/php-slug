<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\ValueObject;

use JsonSerializable;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;

/** Immutable slug value accepted only after profile-specific canonical validation. */
final readonly class Slug implements JsonSerializable
{
    /** Private construction ensures every value has passed a profile's canonical gate. */
    private function __construct(public string $value) {}

    /** Creates a slug after the profile confirms the value is already canonical. */
    public static function fromProfile(SlugProfileInterface $profile, string $canonicalValue): self
    {
        $profile->assertCanonicalSlug($canonicalValue);

        return new self($canonicalValue);
    }

    /** Returns the canonical slug string used in JSON output. */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
