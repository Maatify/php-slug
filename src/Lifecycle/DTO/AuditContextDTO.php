<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

final readonly class AuditContextDTO implements JsonSerializable
{
    public function __construct(
        public ?string $actorKey = null,
        public ?string $reason = null,
        public ?string $correlationKey = null,
        public ?string $idempotencyKey = null,
    ) {
        if ($actorKey !== null) {
            self::assertAuditString($actorKey, 191, 'actorKey');
        }
        if ($reason !== null) {
            self::assertReason($reason);
        }
        if ($correlationKey !== null) {
            self::assertAuditString($correlationKey, 191, 'correlationKey');
        }
        if ($idempotencyKey !== null) {
            self::assertAuditString($idempotencyKey, 191, 'idempotencyKey');
        }
    }

    private static function assertAuditString(string $value, int $maxCodePoints, string $field): void
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

    private static function assertReason(string $value, int $maxCodePoints = 500, string $field = 'reason'): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'actor_key' => $this->actorKey,
            'reason' => $this->reason,
            'correlation_key' => $this->correlationKey,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
