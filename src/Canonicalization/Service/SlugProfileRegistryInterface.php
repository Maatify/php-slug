<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

interface SlugProfileRegistryInterface
{
    public function register(SlugProfileInterface $profile): void;

    public function get(SlugProfileKey $key): SlugProfileInterface;

    public function has(SlugProfileKey $key): bool;
}
