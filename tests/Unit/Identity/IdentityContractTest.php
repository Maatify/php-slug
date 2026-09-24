<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Identity;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IdentityContractTest extends TestCase
{
    public function testSlugHasOnlyPrivateConstructorAndProfileFactory(): void
    {
        $constructor = (new ReflectionClass(Slug::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame('hello', Slug::fromProfile(ContractFixtures::profile(), 'hello')->value);
    }

    public function testScopePreservesNullDimensionsAndRejectsExplicitEmptyDimensions(): void
    {
        $scope = new SlugScope('catalog', null, null);

        self::assertNull($scope->localeKey);
        self::assertNull($scope->contextKey);
        $this->expectException(SlugInvalidArgumentException::class);
        new SlugScope('catalog', '', null);
    }

    public function testScopeDimensionsAreNotCollapsedIntoEachOther(): void
    {
        $withoutLocale = new SlugScope('catalog', null, null);
        $withLocale = new SlugScope('catalog', 'en', null);

        self::assertNotSame($withoutLocale, $withLocale);
        self::assertNull($withoutLocale->localeKey);
        self::assertSame('en', $withLocale->localeKey);
    }

    public function testIdentityBoundariesRejectInvalidOpaqueValues(): void
    {
        $this->expectException(SlugInvalidArgumentException::class);
        new EntityReference('product', "bad\0key");
    }

    public function testProfileKeyUsesVersionedLowercaseContract(): void
    {
        self::assertSame('custom-v12', (new SlugProfileKey('custom-v12'))->value);
        $this->expectException(SlugInvalidArgumentException::class);
        new SlugProfileKey('Custom-v1');
    }
}
