<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Factory;

use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\UnicodeSlugProfile;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;

final class SlugProfileRegistryFactory
{
    public static function createBuiltIn(): SlugProfileRegistryInterface
    {
        $registry = new SlugProfileRegistry();
        $registry->register(new UnicodeSlugProfile());
        $registry->register(new AsciiSlugProfile());
        return $registry;
    }

    private function __construct() {}
}
