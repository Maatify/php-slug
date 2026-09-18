<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Resolution;

use Maatify\Slug\Query\Criteria\ResolutionCriteria;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Query\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Query\Enum\MatchKindEnum;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Query\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Query\Resolution\SlugQueryService;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SlugQueryServiceTest extends TestCase
{
    public function testInvalidLookupReturnsNoneWithoutTouchingPersistence(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $registry = (new ReflectionClass(PdoRegistryRepository::class))->newInstanceWithoutConstructor();
        $availability = (new ReflectionClass(SlugAvailabilityChecker::class))->newInstanceWithoutConstructor();
        $capabilities = (new ReflectionClass(PdoCapabilityGuard::class))->newInstanceWithoutConstructor();
        $service = new SlugQueryService($profiles, $registry, $availability, $capabilities);
        $scope = new ScopeProfileRequestDTO(new SlugScope('unit-resolution', null, null), new SlugProfileKey('ascii-v1'));

        $result = $service->resolve(new ResolutionCriteria($scope, "bad\0segment"));

        self::assertSame(InputFormCanonicalityEnum::INVALID, $result->inputCanonicality);
        self::assertSame(MatchKindEnum::NONE, $result->matchKind);
        self::assertNull($result->lookupCanonicalSlug);
        self::assertNull($result->bindingRevision);
    }

    public function testEngineSurfaceIsClosedToTheSixPublishedContracts(): void
    {
        $interfaces = array_map(static fn(ReflectionClass $interface): string => $interface->getName(), (new ReflectionClass(\Maatify\Slug\Engine\SlugEngine::class))->getInterfaces());
        sort($interfaces);

        self::assertSame([
            'Maatify\\Slug\\Lifecycle\\Contract\\SlugLifecycleServiceInterface',
            'Maatify\\Slug\\Management\\Contract\\SlugManagementQueryInterface',
            'Maatify\\Slug\\Profile\\Contract\\SlugProfileRegistryInterface',
            'Maatify\\Slug\\Query\\Contract\\SlugQueryServiceInterface',
            'Maatify\\Slug\\Scope\\Contract\\SlugScopeRegistryInterface',
            'Maatify\\Slug\\Text\\Contract\\SlugTextServiceInterface',
        ], $interfaces);
        self::assertTrue((new ReflectionClass(\Maatify\Slug\Engine\SlugEngine::class))->getConstructor()?->isPrivate());
    }
}
