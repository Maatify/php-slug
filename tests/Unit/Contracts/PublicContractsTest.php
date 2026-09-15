<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use JsonSerializable;
use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AdoptAliasCommand;
use Maatify\Slug\Command\AdoptCurrentCommand;
use Maatify\Slug\Command\AdoptHistoricalCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\AssignGeneratedCommand;
use Maatify\Slug\Command\AtomicTransferCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Command\ChangeGeneratedCommand;
use Maatify\Slug\Command\DeactivateBindingCommand;
use Maatify\Slug\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Command\PurgeBindingCommand;
use Maatify\Slug\Command\ReactivateAliasCommand;
use Maatify\Slug\Command\ReactivateBindingCommand;
use Maatify\Slug\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Command\ReleaseClaimCommand;
use Maatify\Slug\Command\RestoreHistoricalCommand;
use Maatify\Slug\Command\RetireAliasCommand;
use Maatify\Slug\Command\TransitionScopeCommand;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AliasDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateDTO;
use Maatify\Slug\DTO\BindingStateResultDTO;
use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugAvailabilityDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\DTO\SlugResolutionDTO;
use Maatify\Slug\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Criteria\AliasCriteria;
use Maatify\Slug\Criteria\AvailabilityCriteria;
use Maatify\Slug\Criteria\BindingCriteria;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\HistoryCriteria;
use Maatify\Slug\Criteria\RegistryCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\Criteria\ScopeCriteria;
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
            ResolutionCriteria::class, ScopeCriteria::class,
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
            ScopeTransitionClaimIntentDTO::class, ScopeTransitionResultDTO::class, SlugAvailabilityDTO::class,
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
