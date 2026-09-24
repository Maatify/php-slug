<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Claim selection mode used by scope transition intents. */
enum ClaimIntentModeEnum: string
{
    case EXACT = 'EXACT';
    case GENERATED = 'GENERATED';
}
