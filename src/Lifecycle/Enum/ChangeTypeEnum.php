<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Mutation categories emitted by binding and alias lifecycle operations. */
enum ChangeTypeEnum: string
{
    case ASSIGNED = 'ASSIGNED';
    case CHANGED = 'CHANGED';
    case RESTORED = 'RESTORED';
    case DEACTIVATED = 'DEACTIVATED';
    case REACTIVATED = 'REACTIVATED';
    case RELEASED = 'RELEASED';
    case RELEASED_ALL = 'RELEASED_ALL';
    case ALIAS_ADDED = 'ALIAS_ADDED';
    case ALIAS_RETIRED = 'ALIAS_RETIRED';
    case ALIAS_REACTIVATED = 'ALIAS_REACTIVATED';
    case ALIAS_PROMOTED = 'ALIAS_PROMOTED';
}
