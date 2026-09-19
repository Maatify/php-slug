<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Binding;

interface BindingMaintenanceRepositoryInterface
{
    public function purge(int $bindingId): void;
}
