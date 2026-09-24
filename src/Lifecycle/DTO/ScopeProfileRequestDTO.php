<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Immutable request identifying a scope together with its expected profile key. */
final readonly class ScopeProfileRequestDTO implements JsonSerializable
{
    /** Preserves the profile expectation used to prevent cross-profile scope access. */
    public function __construct(
        public SlugScope $scope,
        public SlugProfileKey $expectedProfileKey,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope,
            'expected_profile_key' => $this->expectedProfileKey->value,
        ];
    }
}
