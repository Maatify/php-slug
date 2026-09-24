<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Enum;

/** Outcomes used when a candidate already belongs to the requesting binding. */
enum SameBindingDecisionEnum: string
{
    case NO_MATCH = 'NO_MATCH';
    case REJECT_ASSIGNMENT = 'REJECT_ASSIGNMENT';
    case NATURAL_NO_OP = 'NATURAL_NO_OP';
    case RESTORE_HISTORICAL = 'RESTORE_HISTORICAL';
    case REJECT_HISTORICAL_RESTORE = 'REJECT_HISTORICAL_RESTORE';
    case ADD_ALIAS = 'ADD_ALIAS';
    case REJECT_ALIAS_OPERATION = 'REJECT_ALIAS_OPERATION';
    case RETIRE_ALIAS = 'RETIRE_ALIAS';
    case REACTIVATE_ALIAS = 'REACTIVATE_ALIAS';
    case PROMOTE_ALIAS = 'PROMOTE_ALIAS';
}
