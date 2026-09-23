<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;

/** Immutable instruction describing how a source current claim is replaced during transfer. */
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
