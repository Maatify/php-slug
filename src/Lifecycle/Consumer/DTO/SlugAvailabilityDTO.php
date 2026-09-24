<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Read result that preserves requested input, canonicalization, ownership, and advisory status. */
final readonly class SlugAvailabilityDTO implements JsonSerializable
{
    /** Availability is intentionally advisory; claim operations remain authoritative. */
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

    /**
     * Returns the availability decision without exposing alternate internal state.
     *
     * @return array<string, mixed>
     */
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
