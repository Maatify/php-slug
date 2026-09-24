<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** Immutable scope/profile and entity identity for one binding. */
final readonly class BindingIdentityDTO implements JsonSerializable
{
    /** Stores the complete identity required to address a binding. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public EntityReference $entity,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scope_profile' => $this->scopeProfile,
            'entity' => [
                'entity_type' => $this->entity->entityType,
                'entity_key' => $this->entity->entityKey,
            ],
        ];
    }
}
