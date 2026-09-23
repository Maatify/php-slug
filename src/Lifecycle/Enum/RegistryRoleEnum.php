<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Roles a slug claim may hold in the package-owned registry. */
enum RegistryRoleEnum: string
{
    case CURRENT_CANONICAL = 'CURRENT_CANONICAL';
    case HISTORICAL_CANONICAL = 'HISTORICAL_CANONICAL';
    case ACTIVE_ALIAS = 'ACTIVE_ALIAS';
    case RETIRED_ALIAS = 'RETIRED_ALIAS';
}
