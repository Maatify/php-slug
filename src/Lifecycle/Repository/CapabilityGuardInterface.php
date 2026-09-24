<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository;

/** Verifies that the connected database can satisfy the package persistence contract. */
interface CapabilityGuardInterface
{
    /** Fails closed when driver, connection, or installed-schema capabilities are unsupported. */
    public function assertInstalledSchemaSupported(): void;
}
