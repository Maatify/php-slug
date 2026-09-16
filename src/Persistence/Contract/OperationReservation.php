<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Contract;

final readonly class OperationReservation
{
    public function __construct(
        public OperationRecord $operation,
        public bool $created,
    ) {}

    public function isReplay(): bool
    {
        return ! $this->created && $this->operation->status === 'COMMITTED';
    }
}
