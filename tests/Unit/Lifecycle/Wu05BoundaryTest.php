<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Lifecycle;

use Maatify\Slug\Contract\SlugLifecycleServiceInterface;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\History\HistoryEventDraft;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Wu05BoundaryTest extends TestCase
{
    public function testConcreteServiceExposesOnlyTheWu05OperationSet(): void
    {
        $reflection = new ReflectionClass(\Maatify\Slug\Lifecycle\SlugLifecycleService::class);
        $publicMethods = array_values(array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn(\ReflectionMethod $method): bool => $method->getName() !== '__construct'
                    && $method->getDeclaringClass()->getName() === $reflection->getName(),
            ),
        ));
        sort($publicMethods);

        $expected = [
            'addAlias',
            'assignExact',
            'assignGenerated',
            'atomicTransfer',
            'changeExact',
            'changeGenerated',
            'deactivate',
            'promoteAliasToCurrent',
            'purgeBinding',
            'reactivate',
            'reactivateAlias',
            'releaseAllOwnership',
            'releaseClaim',
            'restoreHistorical',
            'retireAlias',
        ];
        sort($expected);

        self::assertSame($expected, $publicMethods);
        self::assertFalse($reflection->implementsInterface(SlugLifecycleServiceInterface::class));
        self::assertNotContains('transitionScope', $publicMethods);
        self::assertNotContains('adoptCurrent', $publicMethods);
        self::assertNotContains('adoptHistorical', $publicMethods);
        self::assertNotContains('adoptAlias', $publicMethods);
    }

    public function testHistoryDraftCanRepresentAnImmutableClaimSnapshot(): void
    {
        $profile = new SlugProfileRegistry();
        $profile->register(new TestSlugProfile());
        $slugProfile = $profile->get(new SlugProfileKey('ascii-v1'));
        $scope = new SlugScope('unit', null, null);
        $entity = new EntityReference('product', '1');
        $slug = Slug::fromProfile($slugProfile, 'current');
        $draft = new HistoryEventDraft(
            10,
            4,
            HistoryEventTypeEnum::CHANGED,
            $scope,
            $entity,
            $slug,
            Slug::fromProfile($slugProfile, 'previous'),
            RegistryRoleEnum::CURRENT_CANONICAL,
            RegistryRoleEnum::HISTORICAL_CANONICAL,
            null,
            null,
            null,
            null,
            new AuditContextDTO(actorKey: 'unit-test'),
            new DateTimeImmutable('2026-01-01T00:00:00.123456Z'),
        );

        self::assertNotNull($draft->slugSnapshot);
        self::assertSame('current', $draft->slugSnapshot->value);
        self::assertSame('previous', $draft->previousSlugSnapshot?->value);
        self::assertSame(RegistryRoleEnum::HISTORICAL_CANONICAL, $draft->previousClaimRoleSnapshot);
    }

    public function testHistoryClaimSnapshotsMustKeepPreviousValuesPaired(): void
    {
        $profile = new TestSlugProfile();

        $this->expectException(SlugInvalidArgumentException::class);
        new HistoryEventDTO(
            1,
            10,
            1,
            HistoryEventTypeEnum::ASSIGNED,
            new SlugScope('unit', null, null),
            new EntityReference('product', '1'),
            Slug::fromProfile($profile, 'current'),
            Slug::fromProfile($profile, 'previous'),
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-01-01T00:00:00.123456Z'),
            null,
        );
    }
}
