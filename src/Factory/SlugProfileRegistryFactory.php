<?php

declare(strict_types=1);

namespace Maatify\Slug\Factory;

use Maatify\Slug\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Profile\BuiltIn\UnicodeSlugProfile;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;

final class SlugProfileRegistryFactory
{
    public static function createBuiltIn(): SlugProfileRegistryInterface
    {
        $registry = new SlugProfileRegistry();
        $registry->register(new UnicodeSlugProfile());
        $registry->register(new AsciiSlugProfile());
        return $registry;
    }

    private function __construct()
    {
    }
}
