<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdoTransactionCoordinatorTest extends TestCase
{
    public function testGeneratedSavepointNamesUseTheClosedPackageFormat(): void
    {
        /** @var PDO $pdo */
        $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
        $coordinator = new PdoTransactionCoordinator($pdo);

        for ($index = 0; $index < 16; $index++) {
            self::assertMatchesRegularExpression('/\Amaa_slug_sp_[A-F0-9]{32}\z/', $coordinator->newSavepointName());
        }
    }
}
