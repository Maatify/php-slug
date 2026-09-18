<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Lifecycle\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use PHPUnit\Framework\TestCase;

final class GeneratedCandidateSequenceTest extends TestCase
{
    public function testUsesTheAcceptedBoundedOrderWithoutMinusOne(): void
    {
        $sequence = (new GeneratedCandidateSequence())->fromSource(new TestSlugProfile(), 'Product');

        self::assertCount(1000, $sequence);
        self::assertSame('product', $sequence[0]->value);
        self::assertSame('product-2', $sequence[1]->value);
        self::assertSame('product-9', $sequence[8]->value);
        self::assertSame('product-10', $sequence[9]->value);
        self::assertSame('product-1000', $sequence[999]->value);
        self::assertNotContains('product-1', array_map(static fn($slug): string => $slug->value, $sequence));
    }

    public function testLengthBoundaryIsDelegatedToTheWU02Generator(): void
    {
        $profile = new TestSlugProfile(new SlugProfileKey('ascii-v1'));
        $sequence = (new GeneratedCandidateSequence())->fromSource($profile, str_repeat('a', 160));

        self::assertSame(160, strlen($sequence[0]->value));
        self::assertSame(160, strlen($sequence[1]->value));
        self::assertSame(str_repeat('a', 158) . '-2', $sequence[1]->value);
        self::assertCount(1000, $sequence);
    }
}
