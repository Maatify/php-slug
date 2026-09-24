<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Profile;

use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\Service\RuntimeCompatibilityGuard;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use PHPUnit\Framework\TestCase;

final class RuntimeCompatibilityTest extends TestCase
{
    public function testRequiredRuntimeCapabilitiesAreSupported(): void
    {
        RuntimeCompatibilityGuard::assertSupported();
        self::addToAssertionCount(1);
    }

    public function testBuiltInFactoryWorksOnTheSupportedRuntime(): void
    {
        $registry = SlugProfileRegistryFactory::createBuiltIn();

        self::assertTrue($registry->has(new SlugProfileKey('ascii-v1')));
        self::assertTrue($registry->has(new SlugProfileKey('unicode-v1')));
    }
}
