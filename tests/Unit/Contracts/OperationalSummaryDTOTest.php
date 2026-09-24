<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\DTO\ScopeOperationalSummaryDTO;
use PHPUnit\Framework\TestCase;

final class OperationalSummaryDTOTest extends TestCase
{
    public function testBindingCountsMustBalance(): void
    {
        $scope = new \Maatify\Slug\Lifecycle\DTO\ScopeDTO(1, ContractFixtures::scope(), new \Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey('ascii-v1'), ContractFixtures::date(), ContractFixtures::date());

        $this->expectException(SlugInvalidArgumentException::class);
        new ScopeOperationalSummaryDTO($scope, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    public function testRegistryCountsMustBalance(): void
    {
        $scope = new \Maatify\Slug\Lifecycle\DTO\ScopeDTO(1, ContractFixtures::scope(), new \Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey('ascii-v1'), ContractFixtures::date(), ContractFixtures::date());

        $this->expectException(SlugInvalidArgumentException::class);
        new ScopeOperationalSummaryDTO($scope, 0, 0, 0, 0, 2, 1, 0, 0, 0, 0);
    }

    public function testCountsMustBeNonNegative(): void
    {
        $scope = new \Maatify\Slug\Lifecycle\DTO\ScopeDTO(1, ContractFixtures::scope(), new \Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey('ascii-v1'), ContractFixtures::date(), ContractFixtures::date());

        $this->expectException(SlugInvalidArgumentException::class);
        new ScopeOperationalSummaryDTO($scope, -1, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}
