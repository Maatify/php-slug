<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Resolution;

use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Consumer\Service\SlugAvailabilityChecker;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\Consumer\Service\Resolution\SlugQueryService;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
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
        $interfaces = array_map(static fn(ReflectionClass $interface): string => $interface->getName(), (new ReflectionClass(\Maatify\Slug\Facade\SlugEngine::class))->getInterfaces());
        sort($interfaces);

        self::assertSame([
            'Maatify\\Slug\\Canonicalization\\Service\\SlugProfileRegistryInterface',
            'Maatify\\Slug\\Canonicalization\\Service\\SlugTextServiceInterface',
            'Maatify\\Slug\\Lifecycle\\Consumer\\Service\\SlugQueryServiceInterface',
            'Maatify\\Slug\\Lifecycle\\Management\\Service\\SlugManagementQueryInterface',
            'Maatify\\Slug\\Lifecycle\\Service\\SlugLifecycleServiceInterface',
            'Maatify\\Slug\\Lifecycle\\Service\\SlugScopeRegistryInterface',
        ], $interfaces);
        self::assertTrue((new ReflectionClass(\Maatify\Slug\Facade\SlugEngine::class))->getConstructor()?->isPrivate());
    }
}
