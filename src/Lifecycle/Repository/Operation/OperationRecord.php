<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Operation;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;

final readonly class OperationRecord
{
    public function __construct(
        public int $id,
        public string $operationKey,
        public OperationTypeEnum $operationType,
        public string $requestFingerprint,
        public string $resultType,
        public int $resultSchemaVersion,
        public ?string $resultSnapshot,
        public string $status,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
    ) {
        if ($id < 0 || preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $requestFingerprint) !== 1) {
            throw new SlugPersistenceInvariantException('Operation row contains invalid identity metadata.');
        }
        if (! in_array($resultType, ['mutation', 'transition', 'transfer', 'adoption'], true)) {
            throw new SlugPersistenceInvariantException('Operation row contains an unknown result type.');
        }
        if ($resultSchemaVersion !== 1 || ! in_array($status, ['IN_PROGRESS', 'COMMITTED'], true)) {
            throw new SlugPersistenceInvariantException('Operation row contains invalid state metadata.');
        }
        if ($status === 'IN_PROGRESS' && ($resultSnapshot !== null || $completedAt !== null)) {
            throw new SlugPersistenceInvariantException('IN_PROGRESS operation cannot contain a committed snapshot.');
        }
        if ($status === 'COMMITTED' && ($resultSnapshot === null || $completedAt === null)) {
            throw new SlugPersistenceInvariantException('COMMITTED operation requires a snapshot and completed timestamp.');
        }
    }
}
