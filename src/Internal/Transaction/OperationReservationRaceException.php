<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\Transaction;

use Maatify\Slug\Persistence\Contract\OperationRecord;
use RuntimeException;

final class OperationReservationRaceException extends RuntimeException
{
    public function __construct(public readonly OperationRecord $operation)
    {
        parent::__construct('Operation reservation lost a uniqueness race.');
    }
}
