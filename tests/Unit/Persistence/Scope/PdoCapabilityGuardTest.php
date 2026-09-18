<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use Maatify\Slug\Exception\SlugUnsupportedDriverException;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
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
