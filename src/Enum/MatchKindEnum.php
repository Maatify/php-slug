<?php

declare(strict_types=1);

namespace Maatify\Slug\Enum;

enum MatchKindEnum: string
{
    case CURRENT = 'CURRENT';
    case ALIAS = 'ALIAS';
    case HISTORICAL = 'HISTORICAL';
    case RETIRED_ALIAS = 'RETIRED_ALIAS';
    case NONE = 'NONE';
}
