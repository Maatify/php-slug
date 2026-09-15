<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Scope\Value\SlugScope;

final readonly class ScopeProfileRequestDTO implements JsonSerializable
{
    public function __construct(
        public SlugScope $scope,
        public SlugProfileKey $expectedProfileKey,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope,
            'expected_profile_key' => $this->expectedProfileKey->value,
        ];
    }
}
