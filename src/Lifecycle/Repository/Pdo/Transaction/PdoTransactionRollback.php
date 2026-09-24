<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Transaction;

/**
 * Internal transaction result requesting rollback before returning its value.
 *
 * This is deliberately a value object, not an exception: uniqueness races are
 * expected persistence outcomes and must not introduce a general control-flow
 * RuntimeException into the package taxonomy.
 */
final readonly class PdoTransactionRollback
{
    /** Carries a callback result through the rollback control path. */
    public function __construct(public mixed $value) {}
}
