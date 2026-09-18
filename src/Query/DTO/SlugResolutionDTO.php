<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\DTO;

use JsonSerializable;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Query\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Query\Enum\MatchKindEnum;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Shared\Validation\DTOAssertions;
use Maatify\Slug\Text\Value\Slug;

final readonly class SlugResolutionDTO implements JsonSerializable
{
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
            DTOAssertions::nonNegative($bindingRevision, 'bindingRevision');
        }
    }

    /** @return array<string, mixed> */
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
