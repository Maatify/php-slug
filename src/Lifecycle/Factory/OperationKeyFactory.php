<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Factory;

use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Throwable;

final class OperationKeyFactory
{
    public function create(string $failureMessage = 'Unable to allocate an operation key.'): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            throw new SlugPersistenceInvariantException($failureMessage, 0, $throwable);
        }
    }
}
