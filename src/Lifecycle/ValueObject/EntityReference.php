<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\ValueObject;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable host entity identity used to address a binding. */
final readonly class EntityReference implements JsonSerializable
{
    /** Validates bounded, path-safe entity type and key dimensions. */
    public function __construct(
        public string $entityType,
        public string $entityKey,
    ) {
        self::assertDimension($entityType, 63, 'entityType');
        self::assertDimension($entityKey, 191, 'entityKey');
    }

    /** Enforces the shared entity dimension safety and length contract. */
    private static function assertDimension(string $value, int $maxCodePoints, string $field): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1 || strpbrk($value, '/\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }
        if (preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s has forbidden boundary whitespace.', $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
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
