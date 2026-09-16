<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CriteriaValidationTest extends TestCase
{
    public function testRegistrySlugPrefixAcceptsValidUtf8WithoutIdentityNormalization(): void
    {
        $scope = ContractFixtures::identity(1)->scopeProfile;
        $page = new PageRequest(1, 25);
        $accepted = ['', " leading/trailing ", "path\\part", "bad\0value", "bad\u{200D}value", str_repeat('a', 161), 'Cafe-١'];

        foreach ($accepted as $slugPrefix) {
            $registry = new RegistrySearchCriteria($scope, $page, $slugPrefix);

            self::assertSame($slugPrefix, $registry->slugPrefix);
        }
    }

    public function testRegistrySlugPrefixRejectsInvalidUtf8(): void
    {
        $scope = ContractFixtures::identity(1)->scopeProfile;
        $page = new PageRequest(1, 25);

        $this->expectException(SlugInvalidArgumentException::class);
        new RegistrySearchCriteria($scope, $page, "invalid\xC3\x28");
    }

    public function testBindingEntityKeyPrefixAllowsSqlEscapeCharacterButRetainsSafetyEnvelope(): void
    {
        $scope = ContractFixtures::identity(1)->scopeProfile;
        $page = new PageRequest(1, 25);
        $accepted = ['path\\part'];
        foreach ($accepted as $value) {
            $criteria = new BindingSearchCriteria($scope, $page, 'product', $value);
            self::assertSame($value, $criteria->entityKeyPrefix);
        }

        $invalid = ['', " leading", "trailing ", "path/part", "bad\0value", "bad\u{200D}value"];

        foreach ($invalid as $value) {
            try {
                new BindingSearchCriteria($scope, $page, 'product', $value);
                self::fail('Invalid entityKeyPrefix was accepted.');
            } catch (SlugInvalidArgumentException $exception) {
                self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
            }
        }

        $tooLong = str_repeat('a', 192);
        $this->expectException(SlugInvalidArgumentException::class);
        new BindingSearchCriteria($scope, $page, 'product', $tooLong);
    }
}
