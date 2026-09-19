<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use Maatify\Slug\Lifecycle\Exception\SlugUnsupportedDriverException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdoCapabilityGuardTest extends TestCase
{
    public function testGuardRejectsAnUnavailableDriverBeforeAnyCapabilityProbe(): void
    {
        /** @var PDO $pdo */
        $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();

        $this->expectException(SlugUnsupportedDriverException::class);
        (new PdoCapabilityGuard($pdo))->assertSupported();
    }
}
