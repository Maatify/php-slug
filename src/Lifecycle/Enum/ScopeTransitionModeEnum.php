<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Supported scope transition semantics. */
enum ScopeTransitionModeEnum: string
{
    case MOVE = 'MOVE';
    case PARALLEL = 'PARALLEL';
}
