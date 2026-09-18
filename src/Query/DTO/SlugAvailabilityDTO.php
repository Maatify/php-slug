<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\DTO;

use JsonSerializable;
use Maatify\Slug\Query\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Text\Value\Slug;

final readonly class SlugAvailabilityDTO implements JsonSerializable
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $requestedInput,
        public ?Slug $canonicalSlug,
        public AvailabilityStatusEnum $status,
        public ?BindingDTO $owner,
        public bool $advisory,
    ) {
        if (! $advisory) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Slug availability is advisory in RC1.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scope_profile' => $this->scopeProfile,
            'requested_input' => $this->requestedInput,
            'canonical_slug' => $this->canonicalSlug?->value,
            'status' => $this->status->value,
            'owner' => $this->owner,
            'advisory' => $this->advisory,
        ];
    }
}
