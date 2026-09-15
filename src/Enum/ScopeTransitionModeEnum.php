<?php

declare(strict_types=1);

namespace Maatify\Slug\Enum;

enum ScopeTransitionModeEnum: string
{
    case MOVE = 'MOVE';
    case PARALLEL = 'PARALLEL';
}
