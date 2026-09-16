<?php

declare(strict_types=1);

namespace Maatify\Slug\Identity;

use JsonSerializable;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;

final readonly class Slug implements JsonSerializable
{
    private function __construct(public string $value) {}

    public static function fromProfile(SlugProfileInterface $profile, string $canonicalValue): self
    {
        $profile->assertCanonicalSlug($canonicalValue);

        return new self($canonicalValue);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
