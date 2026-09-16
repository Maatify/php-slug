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
    public function testSearchPrefixesAcceptOpaqueUnicodeWithoutNormalization(): void
    {
        $scope = ContractFixtures::identity(1)->scopeProfile;
        $page = new PageRequest(1, 25);
        $entityPrefix = 'Part ١';
        $slugPrefix = 'Cafe-١';

        $binding = new BindingSearchCriteria($scope, $page, 'product', $entityPrefix);
        $registry = new RegistrySearchCriteria($scope, $page, $slugPrefix);

        self::assertSame($entityPrefix, $binding->entityKeyPrefix);
        self::assertSame($slugPrefix, $registry->slugPrefix);
    }

    public function testSearchPrefixesRejectEmptyBoundaryForbiddenAndOversizedValues(): void
    {
        $scope = ContractFixtures::identity(1)->scopeProfile;
        $page = new PageRequest(1, 25);
        $invalid = ['', " leading", "trailing ", "path/part", "path\\part", "bad\0value", "bad\u{200D}value"];

        foreach ($invalid as $value) {
            try {
                new BindingSearchCriteria($scope, $page, 'product', $value);
                self::fail('Invalid entityKeyPrefix was accepted.');
            } catch (SlugInvalidArgumentException $exception) {
                self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
            }

            try {
                new RegistrySearchCriteria($scope, $page, $value);
                self::fail('Invalid slugPrefix was accepted.');
            } catch (SlugInvalidArgumentException $exception) {
                self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
            }
        }

        $tooLong = str_repeat('a', 192);
        $this->expectException(SlugInvalidArgumentException::class);
        new BindingSearchCriteria($scope, $page, 'product', $tooLong);
    }
}
