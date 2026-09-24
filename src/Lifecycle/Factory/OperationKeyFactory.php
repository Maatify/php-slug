<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Factory;

use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Throwable;

/** Creates random operation keys used to identify persisted lifecycle operations. */
final class OperationKeyFactory
{
    /** Returns a 32-character hexadecimal key or wraps randomness failure as an invariant error. */
    public function create(string $failureMessage = 'Unable to allocate an operation key.'): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            throw new SlugPersistenceInvariantException($failureMessage, 0, $throwable);
        }
    }
}
