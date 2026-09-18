<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Ownership\SameBindingDecisionEnum;
use Maatify\Slug\Lifecycle\Ownership\SameBindingOwnershipClassifier;
use PHPUnit\Framework\TestCase;

final class SameBindingOwnershipClassifierTest extends TestCase
{
    public function testAssignNeverPromotesAnyRetainedRole(): void
    {
        $classifier = new SameBindingOwnershipClassifier();

        foreach (RegistryRoleEnum::cases() as $role) {
            self::assertSame(
                SameBindingDecisionEnum::REJECT_ASSIGNMENT,
                $classifier->classify(OperationTypeEnum::ASSIGN_EXACT, $role),
            );
            self::assertSame(
                SameBindingDecisionEnum::REJECT_ASSIGNMENT,
                $classifier->classify(OperationTypeEnum::ASSIGN_GENERATED, $role),
            );
        }
    }

    public function testChangeRestoreAliasAndPromotionMatrixIsExplicit(): void
    {
        $classifier = new SameBindingOwnershipClassifier();

        self::assertSame(
            SameBindingDecisionEnum::NATURAL_NO_OP,
            $classifier->classify(OperationTypeEnum::CHANGE_EXACT, RegistryRoleEnum::CURRENT_CANONICAL),
        );
        self::assertSame(
            SameBindingDecisionEnum::RESTORE_HISTORICAL,
            $classifier->classify(OperationTypeEnum::CHANGE_GENERATED, RegistryRoleEnum::HISTORICAL_CANONICAL),
        );
        self::assertSame(
            SameBindingDecisionEnum::NATURAL_NO_OP,
            $classifier->classify(OperationTypeEnum::ADD_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS),
        );
        self::assertSame(
            SameBindingDecisionEnum::REACTIVATE_ALIAS,
            $classifier->classify(OperationTypeEnum::REACTIVATE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS),
        );
        self::assertSame(
            SameBindingDecisionEnum::PROMOTE_ALIAS,
            $classifier->classify(OperationTypeEnum::PROMOTE_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS),
        );
        self::assertSame(
            SameBindingDecisionEnum::REJECT_ALIAS_OPERATION,
            $classifier->classify(OperationTypeEnum::CHANGE_EXACT, RegistryRoleEnum::RETIRED_ALIAS),
        );
    }
}
