<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\ResultSnapshot;

use DateTimeImmutable;
use Maatify\Slug\Enum\OperationTypeEnum;

final readonly class OperationSnapshotState
{
    public function __construct(
        public string $operationKey,
        public OperationTypeEnum $operationType,
        public string $resultType,
        public int $resultSchemaVersion,
        public string $status,
        public ?string $resultSnapshot,
        public ?DateTimeImmutable $completedAt,
    ) {}
}
