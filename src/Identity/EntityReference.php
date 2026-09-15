<?php

declare(strict_types=1);

namespace Maatify\Slug\Identity;

use JsonSerializable;

final readonly class EntityReference implements JsonSerializable
{
    public function __construct(
        public string $entityType,
        public string $entityKey,
    ) {
        IdentityValidator::assertDimension($entityType, 63, 'entityType');
        IdentityValidator::assertDimension($entityKey, 191, 'entityKey');
    }

    /** @return array{entity_type: string, entity_key: string} */
    public function jsonSerialize(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_key' => $this->entityKey,
        ];
    }
}
