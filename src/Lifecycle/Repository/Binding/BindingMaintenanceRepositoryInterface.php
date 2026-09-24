<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Binding;

/** Destructive persistence boundary used only after purge authorization. */
interface BindingMaintenanceRepositoryInterface
{
    /** Deletes the package-owned records for a binding within the caller's transaction. */
    public function purge(int $bindingId): void;
}
