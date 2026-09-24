<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Persisted lifecycle status of a binding. */
enum BindingStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case RELEASED = 'RELEASED';
}
