<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

final readonly class BindingStateDTO implements JsonSerializable
{
    public function __construct(
        public BindingStatusEnum $status,
        public ?Slug $currentSlug,
        public int $revision,
        public int $historySequence,
    ) {
        if ($this->revision < 0) {
            throw new SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($this->historySequence < 0) {
            throw new SlugInvalidArgumentException('historySequence must be non-negative.');
        }
        if ($status === BindingStatusEnum::RELEASED && $currentSlug !== null) {
            throw new SlugInvalidArgumentException('Released binding state cannot have a current slug.');
        }
        if ($status !== BindingStatusEnum::RELEASED && $currentSlug === null) {
            throw new SlugInvalidArgumentException('Active or inactive binding state requires a current slug.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'current_slug' => $this->currentSlug?->value,
            'revision' => $this->revision,
            'history_sequence' => $this->historySequence,
        ];
    }
}
