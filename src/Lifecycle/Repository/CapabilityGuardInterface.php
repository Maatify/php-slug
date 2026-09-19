<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository;

interface CapabilityGuardInterface
{
    public function assertInstalledSchemaSupported(): void;
}
