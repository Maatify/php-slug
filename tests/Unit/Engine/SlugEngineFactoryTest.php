<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;
use PHPUnit\Framework\TestCase;

final class SlugEngineFactoryTest extends TestCase
{
    public function testFactoryCreatesAggregateWithoutReadingEnvironmentOrRegisteringProfiles(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $engine = SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), new TestClock());

        self::assertInstanceOf(SlugEngine::class, $engine);
        self::assertTrue($engine->has(new \Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey('ascii-v1')));
    }
}

final class TestClock implements ClockInterface
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
