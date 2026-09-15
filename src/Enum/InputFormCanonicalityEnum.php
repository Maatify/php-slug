<?php

declare(strict_types=1);

namespace Maatify\Slug\Enum;

enum InputFormCanonicalityEnum: string
{
    case CANONICAL = 'CANONICAL';
    case NON_CANONICAL = 'NON_CANONICAL';
    case INVALID = 'INVALID';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
