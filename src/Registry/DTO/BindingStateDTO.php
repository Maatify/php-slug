<?php

declare(strict_types=1);

namespace Maatify\Slug\Registry\DTO;

use JsonSerializable;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Shared\Validation\DTOAssertions;
use Maatify\Slug\Text\Value\Slug;

final readonly class BindingStateDTO implements JsonSerializable
{
    public function __construct(
        public BindingStatusEnum $status,
        public ?Slug $currentSlug,
        public int $revision,
        public int $historySequence,
    ) {
        DTOAssertions::nonNegative($this->revision, 'revision');
        DTOAssertions::nonNegative($this->historySequence, 'historySequence');
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
