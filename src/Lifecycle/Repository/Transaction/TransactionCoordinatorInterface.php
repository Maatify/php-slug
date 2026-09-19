<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Transaction;

interface TransactionCoordinatorInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
