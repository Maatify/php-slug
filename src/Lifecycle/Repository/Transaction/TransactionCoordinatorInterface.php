<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Transaction;

/** Runs a callback inside the caller's transaction boundary. */
interface TransactionCoordinatorInterface
{
    /**
     * Returns the callback result and propagates transaction or callback failures.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
