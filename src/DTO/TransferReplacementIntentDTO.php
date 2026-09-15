<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;
use Maatify\Slug\Enum\ClaimIntentModeEnum;

final readonly class TransferReplacementIntentDTO implements JsonSerializable
{
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
