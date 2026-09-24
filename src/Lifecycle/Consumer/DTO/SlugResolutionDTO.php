<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Read result describing lookup canonicality, match role, owner, and current state. */
final readonly class SlugResolutionDTO implements JsonSerializable
{
    /** Enforces the non-negative revision invariant when a binding was matched. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $requestedSegment,
        public InputFormCanonicalityEnum $inputCanonicality,
        public ?Slug $lookupCanonicalSlug,
        public ?Slug $matchedSlug,
        public MatchKindEnum $matchKind,
        public ?BindingStatusEnum $bindingStatus,
        public ?Slug $currentSlug,
        public ?EntityReference $entity,
        public ?int $bindingRevision,
    ) {
        if ($bindingRevision !== null) {
            if ($bindingRevision < 0) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('bindingRevision must be non-negative.');
            }
        }
    }

    /**
     * Returns the resolution shape, including nulls for unmatched or invalid dimensions.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'scope_profile' => $this->scopeProfile,
            'requested_segment' => $this->requestedSegment,
            'input_canonicality' => $this->inputCanonicality->value,
            'lookup_canonical_slug' => $this->lookupCanonicalSlug?->value,
            'matched_slug' => $this->matchedSlug?->value,
            'match_kind' => $this->matchKind->value,
            'binding_status' => $this->bindingStatus?->value,
            'current_slug' => $this->currentSlug?->value,
            'entity' => $this->entity === null ? null : [
                'entity_type' => $this->entity->entityType,
                'entity_key' => $this->entity->entityKey,
            ],
            'binding_revision' => $this->bindingRevision,
        ];
    }
}
