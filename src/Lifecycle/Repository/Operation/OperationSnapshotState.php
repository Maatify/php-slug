<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Operation;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;

/** Immutable metadata used while decoding a stored operation snapshot. */
final readonly class OperationSnapshotState
{
    /** Preserves the stored operation discriminator and completion state. */
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
