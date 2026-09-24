<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Enum;

/** Classification of lookup input relative to its profile canonical form. */
enum InputFormCanonicalityEnum: string
{
    case CANONICAL = 'CANONICAL';
    case NON_CANONICAL = 'NON_CANONICAL';
    case INVALID = 'INVALID';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
