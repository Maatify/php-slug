<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Operation;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable participant identity used to reserve an idempotent operation. */
final readonly class OperationParticipant
{
    /** Validates binding identity, bounded idempotency key, and participant role. */
    public function __construct(
        public int $bindingId,
        public string $idempotencyKey,
        public string $role,
    ) {
        if ($bindingId < 0) {
            throw new SlugInvalidArgumentException('Operation participant binding id must be non-negative.');
        }
        self::assertIdempotencyKey($idempotencyKey);
        if (! in_array($role, ['SINGLE', 'SOURCE', 'TARGET'], true)) {
            throw new SlugInvalidArgumentException('Unknown operation participant role.');
        }
    }

    /** Enforces the safe bounded string contract for persisted idempotency keys. */
    public static function assertIdempotencyKey(string $value): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException('idempotencyKey must be non-empty valid UTF-8.');
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1 || strpbrk($value, '/\\') !== false) {
            throw new SlugInvalidArgumentException('idempotencyKey contains a forbidden character.');
        }
        if (preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException('idempotencyKey has forbidden boundary whitespace.');
        }
        if (mb_strlen($value, 'UTF-8') > 191) {
            throw new SlugInvalidArgumentException('idempotencyKey exceeds its maximum length.');
        }
    }
}
