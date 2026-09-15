<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\Contracts;

use Maatify\Slug\Identity\SlugProfileKey;

interface SlugProfileRegistryInterface
{
    public function register(SlugProfileInterface $profile): void;

    public function get(SlugProfileKey $key): SlugProfileInterface;

    public function has(SlugProfileKey $key): bool;
}
