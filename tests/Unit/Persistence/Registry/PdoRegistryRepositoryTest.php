<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Registry;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Scope\ScopePersistenceInterface;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoRegistryRepositoryTest extends TestCase
{
    public function testUnknownInsertFailurePropagatesTheOriginalPdoException(): void
    {
        $failure = new PDOException('driver failure');
        $pdo = new RegistryInsertFailurePdo($failure);
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $repository = new PdoRegistryRepository(
            $pdo,
            $profiles,
            $this->createMock(ScopePersistenceInterface::class),
            new RegistryRepositoryTestClock(),
            new PdoCapabilityGuard($pdo),
        );
        $profile = $profiles->get(new SlugProfileKey('ascii-v1'));

        try {
            $repository->insertClaim(1, 1, Slug::fromProfile($profile, 'candidate'), RegistryRoleEnum::CURRENT_CANONICAL);
            self::fail('Expected the original PDOException to propagate.');
        } catch (PDOException $actual) {
            self::assertSame($failure, $actual);
        }
    }
}

final class RegistryInsertFailurePdo extends PDO
{
    public function __construct(private readonly PDOException $failure) {}

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw $this->failure;
    }
}

final class RegistryRepositoryTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
