<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

enum ClaimIntentModeEnum: string
{
    case EXACT = 'EXACT';
    case GENERATED = 'GENERATED';
}
