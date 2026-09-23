<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;

/** Immutable claim intent controlling how a scope transition selects its target claim. */
final readonly class ScopeTransitionClaimIntentDTO implements JsonSerializable
{
    /** Requires a non-empty intent value for either exact or generated mode. */
    public function __construct(
        public ClaimIntentModeEnum $mode,
        public string $value,
    ) {
        if ($value === '') {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Claim intent value must be non-empty.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode->value,
            'value' => $this->value,
        ];
    }
}
