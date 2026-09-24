<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Enum;

/** Advisory availability outcomes for a canonical candidate. */
enum AvailabilityStatusEnum: string
{
    case AVAILABLE = 'AVAILABLE';
    case OWNED_BY_SAME_BINDING = 'OWNED_BY_SAME_BINDING';
    case OWNED_BY_OTHER_BINDING = 'OWNED_BY_OTHER_BINDING';
    case RESERVED = 'RESERVED';
    case INVALID = 'INVALID';
}
