<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\ResultSnapshot;

use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;

final readonly class ResultSnapshotMetadata
{
    public function __construct(
        public string $resultType,
        public int $resultSchemaVersion,
        public OperationTypeEnum $operationType,
        public string $operationKey,
        public string $snapshot,
    ) {
        if (! in_array($resultType, ['mutation', 'transition', 'transfer', 'adoption'], true)) {
            throw new SlugPersistenceInvariantException('Unknown Result Snapshot result type.');
        }
        if ($resultSchemaVersion !== 1) {
            throw new SlugPersistenceInvariantException('Unknown Result Snapshot schema version.');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new SlugPersistenceInvariantException('Result Snapshot operation key is invalid.');
        }
        if ($snapshot === '' || preg_match('//u', $snapshot) !== 1) {
            throw new SlugPersistenceInvariantException('Result Snapshot must be non-empty valid UTF-8.');
        }
    }
}
