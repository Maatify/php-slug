<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Profile;

use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Profile\Runtime\RuntimeCompatibilityGuard;
use PHPUnit\Framework\TestCase;

final class RuntimeCompatibilityTest extends TestCase
{
    public function testLockedTupleIsAcceptedByTheCompatibilityGuard(): void
    {
        RuntimeCompatibilityGuard::assertTupleSupported(74, 15, 1);
        self::addToAssertionCount(1);
    }

    public function testUnsupportedIcuTupleFailsClosed(): void
    {
        $this->expectException(SlugRuntimeCompatibilityException::class);
        RuntimeCompatibilityGuard::assertTupleSupported(75, 15, 1);
    }

    public function testMissingRequiredIntlCapabilityFailsClosed(): void
    {
        $this->expectException(SlugRuntimeCompatibilityException::class);
        RuntimeCompatibilityGuard::assertTupleSupported(74, 15, 1, false, true);
    }

    public function testBuiltInFactoryFollowsTheActualRuntimeTuple(): void
    {
        try {
            SlugProfileRegistryFactory::createBuiltIn();
            self::assertSame(74, $this->actualIcuMajor());
            self::assertSame([15, 1], $this->actualUnicodePrefix());
        } catch (SlugRuntimeCompatibilityException $exception) {
            self::assertInstanceOf(SlugRuntimeCompatibilityException::class, $exception);
            self::assertNotSame([74, 15, 1], [$this->actualIcuMajor(), ...$this->actualUnicodePrefix()]);
        }
    }

    private function actualIcuMajor(): int
    {
        return (int) explode('.', INTL_ICU_VERSION)[0];
    }

    /** @return array{0: int, 1: int} */
    private function actualUnicodePrefix(): array
    {
        $version = \IntlChar::getUnicodeVersion();
        return [$this->versionPart($version[0]), $this->versionPart($version[1])];
    }

    private function versionPart(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return 0;
    }
}
