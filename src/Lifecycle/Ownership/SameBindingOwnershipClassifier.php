<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Ownership;

use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;

/**
 * Internal RC1 policy for repeating a claim operation on a Binding-owned row.
 *
 * This class deliberately has no persistence dependency. The matrix is the
 * single classification point used by claim/allocation code and later
 * lifecycle components.
 */
final class SameBindingOwnershipClassifier
{
    public function classify(OperationTypeEnum $operation, RegistryRoleEnum $role): SameBindingDecisionEnum
    {
        return match ($operation) {
            OperationTypeEnum::ASSIGN_EXACT,
            OperationTypeEnum::ASSIGN_GENERATED => SameBindingDecisionEnum::REJECT_ASSIGNMENT,

            OperationTypeEnum::CHANGE_EXACT,
            OperationTypeEnum::CHANGE_GENERATED => match ($role) {
                RegistryRoleEnum::CURRENT_CANONICAL => SameBindingDecisionEnum::NATURAL_NO_OP,
                RegistryRoleEnum::HISTORICAL_CANONICAL => SameBindingDecisionEnum::RESTORE_HISTORICAL,
                RegistryRoleEnum::ACTIVE_ALIAS,
                RegistryRoleEnum::RETIRED_ALIAS => SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,
            },

            OperationTypeEnum::RESTORE_HISTORICAL => $role === RegistryRoleEnum::HISTORICAL_CANONICAL
                ? SameBindingDecisionEnum::RESTORE_HISTORICAL
                : SameBindingDecisionEnum::REJECT_HISTORICAL_RESTORE,

            OperationTypeEnum::ADD_ALIAS => match ($role) {
                RegistryRoleEnum::HISTORICAL_CANONICAL => SameBindingDecisionEnum::ADD_ALIAS,
                RegistryRoleEnum::ACTIVE_ALIAS => SameBindingDecisionEnum::NATURAL_NO_OP,
                RegistryRoleEnum::CURRENT_CANONICAL,
                RegistryRoleEnum::RETIRED_ALIAS => SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,
            },

            OperationTypeEnum::RETIRE_ALIAS => $role === RegistryRoleEnum::ACTIVE_ALIAS
                ? SameBindingDecisionEnum::RETIRE_ALIAS
                : SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,

            OperationTypeEnum::REACTIVATE_ALIAS => $role === RegistryRoleEnum::RETIRED_ALIAS
                ? SameBindingDecisionEnum::REACTIVATE_ALIAS
                : SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,

            OperationTypeEnum::PROMOTE_ALIAS => $role === RegistryRoleEnum::ACTIVE_ALIAS
                ? SameBindingDecisionEnum::PROMOTE_ALIAS
                : SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,

            default => SameBindingDecisionEnum::NO_MATCH,
        };
    }
}
