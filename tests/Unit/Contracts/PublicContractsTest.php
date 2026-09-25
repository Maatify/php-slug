<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptCurrentCommand;
use Maatify\Slug\Lifecycle\Command\AdoptHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateAliasCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseClaimCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Management\Criteria\AliasCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicContractsTest extends TestCase
{
    public function testCommandsAreFinalReadonlyAndExpectedRevisionNullMeansAbsentOnly(): void
    {
        $classes = [
            AddAliasCommand::class, AdoptAliasCommand::class, AdoptCurrentCommand::class, AdoptHistoricalCommand::class,
            AssignExactCommand::class, AssignGeneratedCommand::class, AtomicTransferCommand::class,
            ChangeExactCommand::class, ChangeGeneratedCommand::class, DeactivateBindingCommand::class,
            PromoteAliasToCurrentCommand::class, PurgeBindingCommand::class, ReactivateAliasCommand::class,
            ReactivateBindingCommand::class, ReleaseAllOwnershipCommand::class, ReleaseClaimCommand::class,
            RestoreHistoricalCommand::class, RetireAliasCommand::class, TransitionScopeCommand::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), $class);
            self::assertTrue($reflection->isReadOnly(), $class);
        }

        $command = new AssignExactCommand(ContractFixtures::identity(1), 'hello', null, new AuditContextDTO());
        self::assertNull($command->expectedRevision);
    }

    public function testCriteriaAreFinalReadonlyAndNotResultDTOs(): void
    {
        $classes = [
            AliasCriteria::class, AvailabilityCriteria::class, BindingCriteria::class, BindingSearchCriteria::class,
            CurrentSlugCriteria::class, HistoryCriteria::class, RegistryCriteria::class, RegistrySearchCriteria::class,
            ResolutionCriteria::class, ScopeCriteria::class, ScopeSearchCriteria::class, HistorySearchCriteria::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), $class);
            self::assertTrue($reflection->isReadOnly(), $class);
            self::assertFalse($reflection->implementsInterface(JsonSerializable::class), $class);
        }
    }

    public function testAllResultDTOsAreFinalReadonlyJsonSerializable(): void
    {
        $classes = [
            AdoptionResultDTO::class, AliasDTO::class, AtomicTransferResultDTO::class, AuditContextDTO::class,
            BindingDTO::class, BindingIdentityDTO::class, BindingStateDTO::class, BindingStateResultDTO::class,
            CanonicalSlugDTO::class, CurrentSlugDTO::class, GeneratedSlugDTO::class, HistoryEventDTO::class,
            LookupCanonicalizationDTO::class, RegistryClaimDTO::class, ScopeDTO::class, ScopeProfileRequestDTO::class,
            ScopeTransitionClaimIntentDTO::class, ScopeTransitionResultDTO::class, ScopeOperationalSummaryDTO::class, SlugAvailabilityDTO::class,
            SlugMutationResultDTO::class, SlugResolutionDTO::class, TransferReplacementIntentDTO::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), $class);
            self::assertTrue($reflection->isReadOnly(), $class);
            self::assertTrue($reflection->implementsInterface(JsonSerializable::class), $class);
        }
    }
}
