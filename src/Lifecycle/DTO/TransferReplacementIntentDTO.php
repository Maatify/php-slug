<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;

/**
 * Immutable replacement intent for a source current claim during atomic transfer.
 * EXACT selects one canonical replacement; GENERATED supplies source text for
 * the ordered replacement candidate sequence.
 */
final readonly class TransferReplacementIntentDTO implements JsonSerializable
{
    /** Requires a non-empty value for the selected exact or generated replacement mode. */
    public function __construct(
        public ClaimIntentModeEnum $mode,
        public string $value,
    ) {
        if ($value === '') {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement intent value must be non-empty.');
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
