<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Operation;

/** Reservation result distinguishing a newly created operation from an existing one. */
final readonly class OperationReservation
{
    /** Stores the operation row and whether this request created it. */
    public function __construct(
        public OperationRecord $operation,
        public bool $created,
    ) {}

    /** Reports whether a committed existing operation can be replayed. */
    public function isReplay(): bool
    {
        return ! $this->created && $this->operation->status === 'COMMITTED';
    }
}
