<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Resolution;

use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Enum\MatchKindEnum;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Resolution\SlugQueryService;
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
            'Maatify\\Slug\\Contract\\SlugLifecycleServiceInterface',
            'Maatify\\Slug\\Contract\\SlugManagementQueryInterface',
            'Maatify\\Slug\\Contract\\SlugQueryServiceInterface',
            'Maatify\\Slug\\Contract\\SlugScopeRegistryInterface',
            'Maatify\\Slug\\Contract\\SlugTextServiceInterface',
            'Maatify\\Slug\\Profile\\Contracts\\SlugProfileRegistryInterface',
        ], $interfaces);
        self::assertTrue((new ReflectionClass(\Maatify\Slug\Engine\SlugEngine::class))->getConstructor()?->isPrivate());
    }
}
